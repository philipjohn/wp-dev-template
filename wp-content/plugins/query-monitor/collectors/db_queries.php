<?php
/*
Copyright 2014 John Blackbourn

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

if ( !defined( 'SAVEQUERIES' ) )
	define( 'SAVEQUERIES', true );
if ( !defined( 'QM_DB_EXPENSIVE' ) )
	define( 'QM_DB_EXPENSIVE', 0.05 );

# QM_DB_LIMIT used to be a hard limit but proved to be more of an annoyance than anything. It now
# just adds a nag to the top of the query table. I might remove it altogether at some point.
if ( !defined( 'QM_DB_LIMIT' ) )
	define( 'QM_DB_LIMIT', 100 );

class QM_Collector_DB_Queries extends QM_Collector {

	public $id = 'db_queries';
	public $db_objects = array();

	public function name() {
		return __( 'Database Queries', 'query-monitor' );
	}

	public function __construct() {
		parent::__construct();
	}

	public function get_errors() {
		if ( !empty( $this->data['errors'] ) )
			return $this->data['errors'];
		return false;
	}

	public function get_expensive() {
		if ( !empty( $this->data['expensive'] ) )
			return $this->data['expensive'];
		return false;
	}

	public static function is_expensive( array $row ) {
		return $row['ltime'] > QM_DB_EXPENSIVE;
	}

	public function process() {

		if ( !SAVEQUERIES )
			return;

		$this->data['total_qs']   = 0;
		$this->data['total_time'] = 0;
		$this->data['errors']     = array();

		$this->db_objects = apply_filters( 'query_monitor_db_objects', array(
			'$wpdb' => $GLOBALS['wpdb']
		) );

		foreach ( $this->db_objects as $name => $db ) {
			if ( is_a( $db, 'wpdb' ) )
				$this->process_db_object( $name, $db );
		}

	}

	protected function log_type( $type ) {

		if ( isset( $this->data['types'][$type] ) )
			$this->data['types'][$type]++;
		else
			$this->data['types'][$type] = 1;

	}

	protected function log_caller( $caller, $ltime, $type ) {

		if ( !isset( $this->data['times'][$caller] ) ) {
			$this->data['times'][$caller] = array(
				'caller' => $caller,
				'calls' => 0,
				'ltime' => 0,
				'types' => array()
			);
		}

		$this->data['times'][$caller]['calls']++;
		$this->data['times'][$caller]['ltime'] += $ltime;

		if ( isset( $this->data['times'][$caller]['types'][$type] ) )
			$this->data['times'][$caller]['types'][$type]++;
		else
			$this->data['times'][$caller]['types'][$type] = 1;

	}

	protected function log_component( $component, $ltime, $type ) {

		if ( !isset( $this->data['component_times'][$component->name] ) ) {
			$this->data['component_times'][$component->name] = array(
				'component' => $component->name,
				'calls'     => 0,
				'ltime'     => 0,
				'types'     => array()
			);
		}

		$this->data['component_times'][$component->name]['calls']++;
		$this->data['component_times'][$component->name]['ltime'] += $ltime;

		if ( isset( $this->data['component_times'][$component->name]['types'][$type] ) )
			$this->data['component_times'][$component->name]['types'][$type]++;
		else
			$this->data['component_times'][$component->name]['types'][$type] = 1;

	}

	protected static function query_compat( array & $query ) {

		list( $query['sql'], $query['ltime'], $query['stack'] ) = $query;

	}

	public function process_db_object( $id, wpdb $db ) {

		$rows       = array();
		$types      = array();
		$total_time = 0;

		foreach ( (array) $db->queries as $query ) {

			if ( ! isset( $query['sql'] ) )
				self::query_compat( $query );

			if ( false !== strpos( $query['stack'], 'wp_admin_bar' ) and !isset( $_REQUEST['qm_display_admin_bar'] ) )
				continue;

			$sql           = $query['sql'];
			$ltime         = $query['ltime'];
			$stack         = $query['stack'];
			$has_component = isset( $query['trace'] );
			$has_results   = isset( $query['result'] );
			$trace         = null;
			$component     = null;

			if ( isset( $query['result'] ) )
				$result = $query['result'];
			else
				$result = null;

			$total_time += $ltime;

			if ( isset( $query['trace'] ) ) {

				$trace       = $query['trace'];
				$component   = $query['trace']->get_component();
				$caller      = $query['trace']->get_caller();
				$caller_name = $caller['id'];
				$caller      = $caller['display'];

			} else {

				$callers = explode( ',', $stack );
				$caller  = trim( end( $callers ) );

				if ( false !== strpos( $caller, '(' ) )
					$caller_name = substr( $caller, 0, strpos( $caller, '(' ) ) . '()';
				else
					$caller_name = $caller;

			}

			$sql  = trim( $sql );
			$type = preg_split( '/\b/', $sql );
			$type = strtoupper( $type[1] );

			$this->log_type( $type );
			$this->log_caller( $caller_name, $ltime, $type );

			if ( $component )
				$this->log_component( $component, $ltime, $type );

			if ( !isset( $types[$type]['total'] ) )
				$types[$type]['total'] = 1;
			else
				$types[$type]['total']++;

			if ( !isset( $types[$type]['callers'][$caller] ) )
				$types[$type]['callers'][$caller] = 1;
			else
				$types[$type]['callers'][$caller]++;

			$row = compact( 'caller', 'caller_name', 'stack', 'sql', 'ltime', 'result', 'type', 'component', 'trace' );

			if ( is_wp_error( $result ) )
				$this->data['errors'][] = $row;

			if ( self::is_expensive( $row ) )
				$this->data['expensive'][] = $row;

			$rows[] = $row;

		}

		$total_qs = count( $rows );

		$this->data['total_qs'] += $total_qs;
		$this->data['total_time'] += $total_time;

		# @TODO put errors in here too:
		# @TODO proper class instead of (object)
		$this->data['dbs'][$id] = (object) compact( 'rows', 'types', 'has_results', 'has_component', 'total_time', 'total_qs' );

	}

}

function register_qm_collector_db_queries( array $qm ) {
	$qm['db_queries'] = new QM_Collector_DB_Queries;
	return $qm;
}

add_filter( 'query_monitor_collectors', 'register_qm_collector_db_queries', 20 );
