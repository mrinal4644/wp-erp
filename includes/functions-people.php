<?php
/**
 * This is a file for peoples API
 *
 * Across the WP-ERP ecosystem, we will need various type of users who are not
 * actually WordPress users. In CRM, Accounting and many other parts, we will
 * need customers, clients, companies, vendors and many such type of users. This
 * is basically a unified way of letting every other components to use the same
 * functionality.
 *
 * Also we are taking advantange of WordPress metadata API to handle various
 * unknown types of data using the meta_key/meta_value system.
 */

/**
 * Get all peoples
 *
 * @since 1.0
 *
 * @param $args array
 *
 * @return array
 */
function erp_get_peoples( $args = [] ) {
    global $wpdb;

    $defaults = [
        'type'       => 'all',
        'number'     => 20,
        'offset'     => 0,
        'orderby'    => 'id',
        'order'      => 'DESC',
        'trashed'    => false,
        'meta_query' => [],
        'count'      => false,
        'include'    => [],
        'exclude'    => [],
        's'          => '',
        'no_object'  => false
    ];

    $args        = wp_parse_args( $args, $defaults );
    $people_type = is_array( $args['type'] ) ? implode( '-', $args['type'] ) : $args['type'];
    $cache_key   = 'erp-people-' . $people_type . '-' . md5( serialize( $args ) );
    $items       = wp_cache_get( $cache_key, 'erp' );

    $pep_tb      = $wpdb->prefix . 'erp_peoples';
    $pepmeta_tb  = $wpdb->prefix . 'erp_peoplemeta';
    $types_tb    = $wpdb->prefix . 'erp_people_types';
    $type_rel_tb = $wpdb->prefix . 'erp_people_type_relations';
    $users_tb    = $wpdb->users;
    $usermeta_tb = $wpdb->usermeta;

    $pep_fileds  = [ 'first_name', 'last_name', 'phone', 'mobile',
            'other', 'fax', 'notes', 'street_1', 'street_2', 'city', 'state', 'postal_code', 'country',
            'currency' ];

    if ( false === $items ) {
        extract( $args );

        $sql         = [];
        $trashed_sql = $trashed ? "`deleted_at` is not null" : "`deleted_at` is null";

        if ( is_array( $type ) ) {
            $type_sql = "and `name` IN ( '" . implode( "','", $type ) . "' )";
        } else {
            $type_sql = ( $type != 'all' ) ? "and `name` = '" . $type ."'" : '';
        }

        $wrapper_select = "SELECT * FROM";

        $sql['select'][] = "( SELECT people.id as id, people.user_id as user_id, people.company as company, people.created_by as created_by, people.created as created, COALESCE( people.email, users.user_email ) AS email,
                COALESCE( people.website, users.user_url ) AS website,";

        $sql['join'][] = "LEFT JOIN $users_tb AS users ON people.user_id = users.ID";

        foreach ( $pep_fileds as $key => $field ) {
            $sql['select'][] = "COALESCE( people.$field, $field.meta_value ) AS $field,";
            $sql['join'][]   = "LEFT JOIN $usermeta_tb AS $field ON people.user_id = $field.user_id AND $field.meta_key = '$field'";
        }

        $sql['select'][] = "GROUP_CONCAT( t.name SEPARATOR ',') AS types";
        $sql['join'][]   = "LEFT JOIN $type_rel_tb AS r ON people.id = r.people_id LEFT JOIN $types_tb AS t ON r.people_types_id = t.id";
        $sql_from_tb     = "FROM $pep_tb AS people";
        $sql['where'][]    = "where 1=1";
        $sql['where'][]    = "AND ( select count(*) from $types_tb
            inner join  $type_rel_tb
                on $types_tb.`id` = $type_rel_tb.`people_types_id`
                where $type_rel_tb.`people_id` = people.`id` $type_sql and $trashed_sql
          ) >= 1";

        $custom_sql['join'] = [];

        $custom_sql['where'][] = 'WHERE 1=1';
        $sql_group_by = "GROUP BY `people`.`id` ) as people";
        $sql_order_by = "ORDER BY $orderby $order";

        // Check if want all data without any pagination
        $sql_limit = ( $number != '-1' && !$count ) ? "LIMIT $number OFFSET $offset" : '';

        if ( $meta_query ) {
            $custom_sql['join'][] = "LEFT JOIN $pepmeta_tb as people_meta on people.id = people_meta.`erp_people_id`";

            $meta_key      = isset( $meta_query['meta_key'] ) ? $meta_query['meta_key'] : '';
            $meta_value    = isset( $meta_query['meta_value'] ) ? $meta_query['meta_value'] : '';
            $compare       = isset( $meta_query['compare'] ) ? $meta_query['compare'] : '=';

            $custom_sql['where'][] = "AND people_meta.meta_key='$meta_key' and people_meta.meta_value='$meta_value'";
        }

        // Check is the row want to search
        if ( ! empty( $s ) ) {
            $custom_sql['where'][] = "AND first_name LIKE '%$s%'";
            $custom_sql['where'][] = "OR last_name LIKE '%$s%'";
            $custom_sql['where'][] = "OR company LIKE '%$s%'";
            $custom_sql['where'][] = "OR email LIKE '%$s%'";
        }

        // Check if args count true, then return total count customer according to above filter
        if ( $count ) {
            $sql_order_by = '';
            $wrapper_select = 'SELECT COUNT(*) as total_number FROM';
        }

        $custom_sql      = apply_filters( 'erp_get_people_pre_where_join', $custom_sql, $args );
        $sql             = apply_filters( 'erp_get_people_pre_query', $sql, $args );
        $custom_group_by = ( ! empty( $custom_sql['group_by'] ) ) ? "GROUP BY " . implode( ', ', $custom_sql['group_by'] ) . ' ' : '';
        $final_query     = $wrapper_select . ' '
                            . implode( ' ', $sql['select'] ) . ' '
                            . $sql_from_tb . ' '
                            . implode( ' ', $sql['join'] ) . ' '
                            . implode( ' ', $sql['where'] ) . ' '
                            . $sql_group_by . ' '
                            . implode( ' ', $custom_sql['join'] ) . ' '
                            . implode( ' ', $custom_sql['where'] ) . ' '
                            . $custom_group_by
                            . $sql_order_by . ' '
                            . $sql_limit;

        // print_r( $final_query ); die();

        if ( $count ) {
            // Only filtered total count of people
            $items = $wpdb->get_var( apply_filters( 'erp_get_people_total_count_query', $final_query, $args ) );
        } else {
            // Fetch results from people table
            $results = $wpdb->get_results( apply_filters( 'erp_get_people_total_query', $final_query, $args ), ARRAY_A );

            array_walk( $results, function( &$results ) {
                $results['types'] = explode(',', $results['types'] );
            });

            $items = ( $no_object ) ? $results : erp_array_to_object( $results );
        }

        wp_cache_set( $cache_key, $items, 'erp' );
    }

    return $items;
}

/**
 * People data delete
 *
 * @since 1.0
 *
 * @param  array  $data
 *
 * @return void
 */
function erp_delete_people( $data = [] ) {

    if ( empty( $data['id'] ) ) {
        return new WP_Error( 'not-ids', __( 'No data found', 'erp' ) );
    }

    if ( empty( $data['type'] ) ) {
        return new WP_Error( 'not-types', __( 'No type found', 'erp' ) );
    }

    $people_ids = [];

    if ( is_array( $data['id'] ) ) {
        foreach ( $data['id'] as $key => $id ) {
            $people_ids[] = $id;
        }
    } else if ( is_int( $data['id'] ) ) {
        $people_ids[] = $data['id'];
    }

    // still do we have any ids to delete?
    if ( ! $people_ids ) {
        return;
    }

    // seems like we got some
    foreach ( $people_ids as $people_id ) {

        do_action( 'erp_before_delete_people', $people_id, $data );

        if ( $data['hard'] ) {
            $people   = \WeDevs\ERP\Framework\Models\People::find( $people_id );
            $type_obj = \WeDevs\ERP\Framework\Models\PeopleTypes::name( $data['type'] )->first();
            $people->removeType( $type_obj );

            $types  = wp_list_pluck( $people->types->toArray(), 'name' );

            if ( empty( $types ) ) {
                $people->delete();
                \WeDevs\ERP\Framework\Models\Peoplemeta::where( 'erp_people_id', $people_id )->delete();
            }

        } else {
            $people   = \WeDevs\ERP\Framework\Models\People::with('types')->find( $people_id );
            $type_obj = \WeDevs\ERP\Framework\Models\PeopleTypes::name( $data['type'] )->first();
            $people->softDeleteType( $type_obj );
        }

        do_action( 'erp_after_delete_people', $people_id, $data );
    }
}

/**
 * People Restore
 *
 * @since 1.0
 *
 * @param  array $data
 *
 * @return void
 */
function erp_restore_people( $data ) {

    if ( empty( $data['id'] ) ) {
        return new WP_Error( 'not-ids', __( 'No data found', 'erp' ) );
    }

    if ( empty( $data['type'] ) ) {
        return new WP_Error( 'not-types', __( 'No type found', 'erp' ) );
    }

    $people_ids = [];

    if ( is_array( $data['id'] ) ) {
        foreach ( $data['id'] as $key => $id ) {
            $people_ids[] = $id;
        }
    } else if ( is_int( $data['id'] ) ) {
        $people_ids[] = $data['id'];
    }

    // still do we have any ids to delete?
    if ( ! $people_ids ) {
        return;
    }

    // seems like we got some
    foreach ( $people_ids as $people_id ) {

        do_action( 'erp_before_restoring_people', $people_id, $data );

        $people   = \WeDevs\ERP\Framework\Models\People::with('types')->find( $people_id );
        $type_obj = \WeDevs\ERP\Framework\Models\PeopleTypes::name( $data['type'] )->first();
        $people->restore( $type_obj );

        do_action( 'erp_after_restoring_people', $people_id, $data );
    }
}

/**
 * Get users as array
 *
 * @since 1.0
 *
 * @param  array   $args
 *
 * @return array
 */
function erp_get_peoples_array( $args = [] ) {
    $users   = [];
    $peoples = erp_get_peoples( $args );

    foreach ( $peoples as $user ) {
        $users[ $user->id ] = ( in_array( 'company', $user->types ) ) ? $user->company : $user->first_name . ' ' . $user->last_name;
    }

    return $users;
}

/**
 * Fetch people count from database
 *
 * @since 1.0
 *
 * @param string $type
 *
 * @return int
 */
function erp_get_peoples_count( $type = 'contact' ) {
    $cache_key = 'erp-people-count-' . $type;
    $count     = wp_cache_get( $cache_key, 'erp' );

    if ( false === $count ) {
        $count = WeDevs\ERP\Framework\Models\People::type( $type )->count();

        wp_cache_set( $cache_key, $count, 'erp' );
    }

    return intval( $count );
}

/**
 * Fetch a single people from database
 *
 * @since 1.0
 *
 * @param int   $id
 *
 * @return array
 */
function erp_get_people( $id = 0 ) {
    return erp_get_people_by( 'id', $id );
}

/**
 * Retrieve people info by a given field
 *
 * @param  string $field
 * @param  mixed  $value
 *
 * @return object
 */
function erp_get_people_by( $field, $value ) {
    global $wpdb;

    if ( empty( $field ) ) {
        return new WP_Error( 'no-field', __( 'No field provided', 'erp' ) );
    }

    if ( empty( $value ) ) {
        return new WP_Error( 'no-value', __( 'No value provided', 'erp' ) );
    }

    $cache_key = 'erp-people-by-' . md5( serialize( $value ) );
    $people    = wp_cache_get( $cache_key, 'erp' );

    if ( false === $people ) {

        $people_fileds = [ 'first_name', 'last_name', 'company', 'phone', 'mobile',
            'other', 'fax', 'notes', 'street_1', 'street_2', 'city', 'state', 'postal_code', 'country',
            'currency' ]; // meta only

        $sql = "SELECT * FROM (
        SELECT people.id as id, people.user_id, people.created_by,

            CASE WHEN people.user_id then user.user_email ELSE people.email END as email,
            CASE WHEN people.user_id then user.user_url ELSE people.website END as website,";


        foreach ( $people_fileds as $field_name ) {
            $sql .= "MAX(CASE WHEN people.user_id AND user_meta.meta_key = '$field_name' then user_meta.meta_value ELSE people.$field_name END) as $field_name,";
        }

        $sql .= "GROUP_CONCAT(DISTINCT p_types.name) as types

        FROM {$wpdb->prefix}erp_peoples as people
        LEFT JOIN {$wpdb->prefix}users AS user on user.ID = people.user_id
        LEFT JOIN {$wpdb->prefix}usermeta AS user_meta on user_meta.user_id = people.user_id
        LEFT JOIN {$wpdb->prefix}erp_people_type_relations as p_types_rel on p_types_rel.people_id = people.id
        LEFT JOIN {$wpdb->prefix}erp_people_types as p_types on p_types.id = p_types_rel.people_types_id
        ";

        $sql .= " GROUP BY people.id ) as people";

        if ( is_array( $value ) ) {
            $separeted_values = "'" . implode( "','", $value ) . "'";

            $sql .= " WHERE $field IN ( $separeted_values )";
        } else {
            $sql .= " WHERE $field = '$value'";
        }

        $results = $wpdb->get_results( $sql );


        $results = array_map( function( $item ) {
            $item->types = explode( ',', $item->types );

            return $item;
        }, $results);


        if ( is_array( $value ) ) {
            $people = erp_array_to_object( $results );
        } else {
            $people = ( ! empty( $results ) ) ? $results[0] : false;
        }

        wp_cache_set( $cache_key, $people, 'erp' );
    }

    return $people;
}

/**
 * Insert a new people
 *
 * @param array $args
 *
 * @return mixed integer on success, false otherwise
 */
function erp_insert_people( $args = array() ) {

    $defaults = array(
        'id'          => null,
        'first_name'  => '',
        'last_name'   => '',
        'email'       => '',
        'company'     => '',
        'phone'       => '',
        'mobile'      => '',
        'other'       => '',
        'website'     => '',
        'fax'         => '',
        'notes'       => '',
        'street_1'    => '',
        'street_2'    => '',
        'city'        => '',
        'state'       => '',
        'postal_code' => '',
        'country'     => '',
        'currency'    => '',
        'type'        => '',
        'user_id'     => 0
    );

    $args        = wp_parse_args( $args, $defaults );
    $errors      = [];
    $main_fields = [];
    $meta_fields = [];

    foreach ( $args as $key => $value ) {
        if ( array_key_exists( $key, $defaults ) ) {
            $main_fields[$key] = $value;
        } else {
            $meta_fields[$key] = $value;
        }
    }

    $main_fields['created_by'] = get_current_user_id();

    $people_type = $main_fields['type'];
    unset( $main_fields['type'] );

    // remove row id to determine if new or update
    if ( isset( $main_fields['id'] ) ) {
        $id = (int) $main_fields['id'];
        unset( $main_fields['id'] );
    } else {
        $id = null;
    }

    // Some validation
    $type_obj = \WeDevs\ERP\Framework\Models\PeopleTypes::name( $people_type )->first();

    // check if a valid people type exists in the database
    if ( null === $type_obj ) {
        return new WP_Error( 'no-type_found', __( 'The people type is invalid.', 'erp' ) );
    }

    // if an empty type provided
    if ( '' == $people_type ) {
        return new WP_Error( 'no-type', __( 'No user type provided.', 'erp' ) );
    }

    if ( 'contact' == $people_type ) {
        if ( empty( $main_fields['user_id'] ) ) {
            // some basic validation
            // Check if contact first name or email or phone provided or not
            if ( empty( $main_fields['first_name'] ) && empty( $main_fields['phone'] ) && empty( $main_fields['email'] ) ) {
                return new WP_Error( 'no-basic-data', __( 'You must need to fillup either first name or phone or email', 'erp' ) );
            }
        }
    }

    // Check if company name provide or not
    if ( 'company' == $people_type ) {
        if ( empty( $main_fields['company'] ) && empty( $main_fields['phone'] ) && empty( $main_fields['email'] ) ) {
            return new WP_Error( 'no-company', __( 'You must need to fillup either Company name or email or phone', 'erp' ) );
        }
    }

    // Check if not empty and valid email
    if ( ! empty( $main_fields['email'] ) && ! is_email( $main_fields['email'] ) ) {
        return new WP_Error( 'invalid-email', __( 'Please provide a valid email address', 'erp' ) );
    }

    $errors = apply_filters( 'erp_people_validation_error', [], $main_fields, $meta_fields );

    if ( !empty( $errors ) ) {
        return $errors;
    }

    if ( ! $id ) {
        // insert either as wp user or new people

        if ( $main_fields['user_id'] ) {
            $user = \get_user_by( 'id', $main_fields['user_id'] );
        } else {
            $user = \get_user_by( 'email', $main_fields['email'] );
        }

        // check if data is alreayd wp user or not
        if ( $user ) {
            $people_obj = \WeDevs\ERP\Framework\Models\People::where( 'user_id', $user->ID )->first();

            // Check if exist in wp user table but not people table
            if ( null == $people_obj ) {
                // Then fetch all data from wp users and insert into people and people meta table
                $main = [];
                $meta = [];
                // $people_field      = erp_get_people_main_field();
                // $people_meta_field = erm_crm_get_contact_meta_fileds();

                $people_fields = $args;

                unset( $people_fields['user_id'] );

                $userdata = \WeDevs\ORM\WP\UserMeta::where( 'user_id', $user->ID )->whereIn( 'meta_key', array_keys( $people_fields ) )->get()->toArray();

                $user_metadata = wp_list_pluck( $userdata, 'meta_value', 'meta_key' );
                // var_dump( $user_metadata ); die();

                foreach ( $people_fields as $key => $value ) {
                    // unset( $value['umeta_id' ], $value['user_id'] );
                    if ( array_key_exists( $key, $user_metadata ) ) {
                        if ( $value == $user_metadata[$key] ) {
                            $same[$key] = $value;
                        } else {
                            $changes[$key] = $value;
                        }
                    } else {
                        $changes[$key] = $value;
                    }
                }

                var_dump( $changes, $same ); die();


                $main_field_data = wp_list_pluck( $main, 'meta_value', 'meta_key' );
                $main_field_data['user_id'] = $user->ID;
                $main_field_data['email'] = $user->user_email;
                $main_field_data['website'] = $user->user_url;
                $main_field_data['created'] = current_time( 'mysql' );

                $people = \WeDevs\ERP\Framework\Models\People::create( $main_field_data );
                $people->assignType( $type_obj );

                if ( $people->id ) {
                    $same_meta = array_map( function( $item, $key ) use( $people ) {
                        return [
                            'erp_people_id' => $people->id,
                            'meta_key' => $key,
                            'meta_value' => $item
                        ];
                    }, $same, array_keys( $same ) );

                    \WeDevs\ERP\Framework\Models\Peoplemeta::insert( $same_meta );
                    return $people->id;
                }

            } else {
                // return error or message people alreay exist in people table
                return new WP_Error( 'already-wpuser-exist', __( 'This user already exist as a people', 'erp' ) );
            }

        } else {
            // So the people is not wp user. That's why we process those data into people table
            $people_obj = \WeDevs\ERP\Framework\Models\People::whereEmail( $main_fields['email'] )->first();

            // Check already email exist in contact table
            if ( $people_obj ) {
                // Yes people alreayd exist with this email
                if ( $people_obj->hasType( $people_type ) ) {
                    // Yes applicable type exist for this people so throw an error
                    return new WP_Error( 'type-exist', sprintf( __( 'This %s people already exists.', 'erp' ), $people_type ) );
                } else {
                    // Not found this type so assign a applicable type
                    $people_obj->assignType( $people_type );
                    return $people_obj->id;
                }
            } else {
                $meta_array = [];
                $main_fields['created'] = current_time( 'mysql' );
                // insert a new
                $people = \WeDevs\ERP\Framework\Models\People::create( $main_fields );

                if ( $people->id ) {
                    $people->assignType( $type_obj );
                    foreach ( $meta_fields as $key => $value ) {
                        $meta_array[] = [
                            'erp_people_id' => $people->id,
                            'meta_key' => $key,
                            'meta_value' => $value,
                        ];
                    }
                    \WeDevs\ERP\Framework\Models\Peoplemeta::insert( $meta_array );

                    return $people->id;
                } else {
                    return new WP_Error( 'no-people-created', __( 'Something wrong, please try again', 'erp' ) );
                }
            }
        }

    } else {
        // Update people or wp_user
        // Check if WP user or not. If WP user, then handle those data into users and usermeta table
        if ( $main_fields['user_id'] ) {
            $user_id = wp_update_user( [
                'ID'         => $main_fields['user_id'],
                'first_name' => $main_fields['first_name'],
                'last_name'  => $main_fields['last_name'],
                'user_url'   => $main_fields['website'],
                'user_email' => $main_fields['email'],
            ] );

            if ( is_wp_error( $user_id ) ) {
                return new WP_Error( 'update-user', $user_id->get_error_message() );
            } else {
                unset( $main_fields['id'], $main_fields['user_id'], $main_fields['email'], $main_fields['website'], $main_fields['type'], $main_fields['company'] );
                $all_fields = array_merge( $main_fields, $meta_fields );
                foreach ( $all_fields as $key => $arg ) {
                    update_user_meta( $user_id, $key, $arg );
                }
            }
        } else {
            // Not a WP user, so simply handle those data into peoples and peoplemeta table
            // do update method here
            WeDevs\ERP\Framework\Models\People::find( $id )->update( $main_fields );
            foreach ( $meta_fields as $key => $value ) {
                erp_people_update_meta( $id, $key, $value );
            }

        }

        do_action( 'erp_update_people', $id, $main_fields );

        return $id;
    }

    return false;
}

/**
 * Add meta data field to a people.
 *
 * @since 1.0
 *
 * @param int    $people_id  People id.
 * @param string $meta_key   Metadata name.
 * @param mixed  $meta_value Metadata value. Must be serializable if non-scalar.
 * @param bool   $unique     Optional. Whether the same key should not be added.
 *                           Default false.
 *
 * @return int|false Meta id on success, false on failure.
 */
function erp_people_add_meta( $people_id, $meta_key, $meta_value, $unique = false ) {
    return add_metadata( 'erp_people', $people_id, $meta_key, $meta_value, $unique);
}

/**
 * Retrieve people meta field for a people.
 *
 * @since 1.0
 *
 * @param int    $people_id People id.
 * @param string $key     Optional. The meta key to retrieve. By default, returns
 *                        data for all keys. Default empty.
 * @param bool   $single  Optional. Whether to return a single value. Default false.
 *
 * @return mixed Will be an array if $single is false. Will be value of meta data
 *               field if $single is true.
 */
function erp_people_get_meta( $people_id, $key = '', $single = false ) {
    return get_metadata( 'erp_people', $people_id, $key, $single);
}

/**
 * Update people meta field based on people id.
 *
 * Use the $prev_value parameter to differentiate between meta fields with the
 * same key and people id.
 *
 * If the meta field for the people does not exist, it will be added.
 *
 * @since 1.0
 *
 * @param int    $people_id  People id.
 * @param string $meta_key   Metadata key.
 * @param mixed  $meta_value Metadata value. Must be serializable if non-scalar.
 * @param mixed  $prev_value Optional. Previous value to check before removing.
 *                           Default empty.
 *
 * @return int|bool Meta id if the key didn't exist, true on successful update,
 *                  false on failure.
 */
function erp_people_update_meta( $people_id, $meta_key, $meta_value, $prev_value = '' ) {
    return update_metadata( 'erp_people', $people_id, $meta_key, $meta_value, $prev_value );
}

/**
 * Remove metadata matching criteria from a people.
 *
 * You can match based on the key, or key and value. Removing based on key and
 * value, will keep from removing duplicate metadata with the same key. It also
 * allows removing all metadata matching key, if needed.
 *
 * @since 1.0
 *
 * @param int    $people_id  People id.
 * @param string $meta_key   Metadata name.
 * @param mixed  $meta_value Optional. Metadata value. Must be serializable if
 *                           non-scalar. Default empty.
 *
 * @return bool True on success, false on failure.
 */
function erp_people_delete_meta( $people_id, $meta_key, $meta_value = '' ) {
    return delete_metadata( 'erp_people', $people_id, $meta_key, $meta_value);
}

/**
 * Get people all main db fields
 *
 * @since 1.1.7
 *
 * @return array
 */
function erp_get_people_main_field() {
    return apply_filters( 'erp_get_people_main_field', [
        'user_id', 'first_name', 'last_name', 'company', 'email', 'phone', 'mobile', 'other', 'website', 'fax', 'notes', 'street_1', 'street_2', 'city', 'state', 'postal_code', 'country', 'currency', 'created_by', 'created'
    ] );
}