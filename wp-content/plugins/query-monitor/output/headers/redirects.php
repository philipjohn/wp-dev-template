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

class QM_Output_Headers_Redirects extends QM_Output_Headers {

	public function output() {

		$data = $this->collector->get_data();

		if ( empty( $data ) )
			return;

		header( sprintf( 'X-QM-Redirect-Trace: %s',
			implode( ', ', $data['trace']->get_stack() )
		) );

	}

}

function register_qm_output_headers_redirects( QM_Output $output = null, QM_Collector $collector ) {
	return new QM_Output_Headers_Redirects( $collector );
}

add_filter( 'query_monitor_output_headers_redirects', 'register_qm_output_headers_redirects', 10, 2 );
