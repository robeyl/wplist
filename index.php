<?php

ini_set( 'display_errors', true );
error_reporting( E_ALL );

$list = fopen( __DIR__ . '/list', 'r' );

echo '<style>
td.details {
	min-width: 30em;
	max-widht: 50%;
	vertical-align: top;
}
table img {
	max-width: 400px;
}
td {
	border-bottom: 1px solid black;
}
td.screenshot {
	padding-bottom: 3em;
}
img.questionable {
	filter: blur(10px);
}
img.questionable:hover {
	filter: none;
}
</style>';

echo '<meta name="google" content="notranslate" />';

echo '<h1>WordPress sites in the top 1m domains list</h1>';

echo 'Latest Log<br><pre>', `tail log`, '</pre><hr/>';

echo '<h2>The sites</h2>';

echo '<p><a href="?version=6.1">Just show Version 6.1*</a></p>';

echo '<table>';

while ( $line = fgets( $list ) ) {
	$line = trim( $line );
	if ( ! $line ) {
		continue;
	}

	$data = json_decode( $line );
	if ( ! $data ) {
		continue;
	}

	$ranked = $data->ranked ?? 0;
	$url   = $data->url ?? '';
	$title = html_entity_decode( $data->title ?? '' );
	$version = $data->version ?? '';
	$desc  = html_entity_decode( $data->description ?? '' );

	$version = htmlentities( $version ) ?: '<em>Unknown</em>';

	if ( isset( $_GET['version'] ) && false === stripos( $version, $_GET['version'] ) ) {
		continue;
	}

	$url_history = $data->url_history ?? [ $data->url ];
	$url_history = implode( ' =&gt; ', $url_history );

	$img_class = '';
	if (
		false !== stripos( $title . $desc, str_rot13( 'cbea' ) ) ||
		false !== stripos( $title . $desc, str_rot13( 'nqhyg' ) ) ||
		false !== stripos( $title . $desc, str_rot13( 'frk' ) ) ||
		false !== stripos( $title . $desc, str_rot13( 'jbzra' ) ) ||
		false !== stripos( $title . $desc, str_rot13( 'kkk' ) )
	) {
		$img_class = 'questionable';
	}

	printf(
		'<tr>
		<td class="screenshot">
			%s
		</td>
		<td class="details">
			#%s <strong>%s</strong><br>
			<a href="%s">%s</a><br>
			WordPress Version %s<br>
			%s
		</td>
		</tr>',
		sprintf(
			'<img src="%s" class="%s" />',
			'https://s.wp.com/mshots/v1/' . urlencode( $url ),
			$img_class,
		),
		number_format( $ranked ),
		htmlentities( $title ),
		$url, $url_history,
		$version,
		htmlentities( $desc )
	);

}

echo '</table>';
