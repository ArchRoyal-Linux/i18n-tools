<?php

require_once dirname( __FILE__ ) . '/not-gettexted.php';
require_once dirname( __FILE__ ) . '/pot-ext-meta.php';
require_once dirname( __FILE__ ) . '/extract/extract.php';

if ( !defined( 'STDERR' ) ) {
	define( 'STDERR', fopen( 'php://stderr', 'w' ) );
}

class MakePOT {
	var $max_header_lines = 30;

	var $projects = array(
		'generic',
		'ar-installer',
	);

	var $rules = array(
		'_' => array('string'),
		'__' => array('string', 'domain'),
		'_e' => array('string', 'domain'),
		'_n' => array('singular', 'plural', null, 'domain'),
		'_n_noop' => array('singular', 'plural', 'domain'),
		'_x' => array('string', 'context', 'domain'),
		'_ex' => array('string', 'context', 'domain'),
		'_nx' => array('singular', 'plural', null, 'context', 'domain'),
		'_nx_noop' => array('singular', 'plural', 'context', 'domain'),
		'_n_js' => array('singular', 'plural'),
		'_nx_js' => array('singular', 'plural', 'context'),
		'esc_attr__' => array('string', 'domain'),
		'esc_html__' => array('string', 'domain'),
		'esc_attr_e' => array('string', 'domain'),
		'esc_html_e' => array('string', 'domain'),
		'esc_attr_x' => array('string', 'context', 'domain'),
		'esc_html_x' => array('string', 'context', 'domain'),
		'comments_number_link' => array('string', 'singular', 'plural'),

		// Deprecated
		'_c' => array('string', 'domain'),
		'_nc' => array('singular', 'plural', null, 'domain'),
		'__ngettext' => array('singular', 'plural', null, 'domain'),
		'__ngettext_noop' => array('singular', 'plural', 'domain'),
	);

	var $ms_files = array();

	var $temp_files = array();

	var $meta = array(
		'default' => array(
			'from-code' => 'utf-8',
			'msgid-bugs-address' => 'https://github.com/ArchRoyal-Linux/installer/issues',
			'language' => 'php',
			'add-comments' => 'translators',
			'comments' => "Copyright (C) {year} {package-name}\nThis file is distributed under the same license as the {package-name} package.",
		),
		'generic' => array(),
		'ar-installer' => array(
			'description' => 'Translation of strings in ArchRoyal installer {version}',
			'copyright-holder' => 'ArchRoyal-Linux',
			'package-name' => 'ArchRoyal-Linux',
			'package-version' => '{version}',
		),
	);

	function __construct($deprecated = true) {
		$this->extractor = new StringExtractor( $this->rules );
	}

	function __destruct() {
		foreach ( $this->temp_files as $temp_file )
			unlink( $temp_file );
	}

	function tempnam( $file ) {
		$tempnam = tempnam( sys_get_temp_dir(), $file );
		$this->temp_files[] = $tempnam;
		return $tempnam;
	}

	function realpath_missing($path) {
		return realpath(dirname($path)).DIRECTORY_SEPARATOR.basename($path);
	}

	function ar_version($dir) {
		$version_php = $dir.'/ar-includes/version.php';
		if ( !is_readable( $version_php ) ) return false;
		return preg_match( '/\$ar_version\s*=\s*\'(.*?)\';/', file_get_contents( $version_php ), $matches )? $matches[1] : false;
	}

	function xgettext($project, $dir, $output_file, $placeholders = array(), $excludes = array(), $includes = array()) {
		$meta = array_merge( $this->meta['default'], $this->meta[$project] );
		$placeholders = array_merge( $meta, $placeholders );
		$meta['output'] = $this->realpath_missing( $output_file );
		$placeholders['year'] = date( 'Y' );
		$placeholder_keys = array_map( create_function( '$x', 'return "{".$x."}";' ), array_keys( $placeholders ) );
		$placeholder_values = array_values( $placeholders );
		foreach($meta as $key => $value) {
			$meta[$key] = str_replace($placeholder_keys, $placeholder_values, $value);
		}

		$originals = $this->extractor->extract_from_directory( $dir, $excludes, $includes );
		$pot = new PO;
		$pot->entries = $originals->entries;

		$pot->set_header( 'Project-Id-Version', $meta['package-name'].' '.$meta['package-version'] );
		$pot->set_header( 'Report-Msgid-Bugs-To', $meta['msgid-bugs-address'] );
		$pot->set_header( 'POT-Creation-Date', gmdate( 'Y-m-d H:i:s+00:00' ) );
		$pot->set_header( 'MIME-Version', '1.0' );
		$pot->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$pot->set_header( 'Content-Transfer-Encoding', '8bit' );
		$pot->set_header( 'PO-Revision-Date', date( 'Y') . '-MO-DA HO:MI+ZONE' );
		$pot->set_header( 'Last-Translator', 'FULL NAME <EMAIL@ADDRESS>' );
		$pot->set_header( 'Language-Team', 'LANGUAGE <LL@li.org>' );
		$pot->set_comment_before_headers( $meta['comments'] );
		$pot->export_to_file( $output_file );
		return true;
	}

	function ar_generic($dir, $args) {
		$defaults = array(
			'project' => 'archroyal-linux',
			'output' => null,
			'default_output' => 'archroyal.pot',
			'includes' => array(),
			'excludes' => array_merge(
				array(),
				$this->ms_files
			),
			'extract_not_gettexted' => false,
			'not_gettexted_files_filter' => false,
		);
		$args = array_merge( $defaults, $args );
		extract( $args );
		$placeholders = array();
		if ( $ar_version = $this->ar_version( $dir ) )
			$placeholders['version'] = $ar_version;
		$output = is_null( $output )? $default_output : $output;
		$res = $this->xgettext( $project, $dir, $output, $placeholders, $excludes, $includes );
		if ( !$res ) return false;

		if ( $extract_not_gettexted ) {
			$old_dir = getcwd();
			$output = realpath( $output );
			chdir( $dir );
			$php_files = NotGettexted::list_php_files('.');
			$php_files = array_filter( $php_files, $not_gettexted_files_filter );
			$not_gettexted = new NotGettexted;
			$res = $not_gettexted->command_extract( $output, $php_files );
			chdir( $old_dir );
			/* Adding non-gettexted strings can repeat some phrases */
			$output_shell = escapeshellarg( $output );
			system( "msguniq --use-first $output_shell -o $output_shell" );
		}
		return $res;
	}

	function ar_installer($dir, $output) {
		$output = is_null( $output )? 'installer.pot' : $output;
		return $this->ar_generic( $dir, array(
			'project' => 'ar-installer',
			'output' => $output,
		) );
	}

	function is_ms_file( $file_name ) {
		$is_ms_file = false;
		$prefix = substr( $file_name, 0, 2 ) === './'? '\./' : '';
		foreach( $this->ms_files as $ms_file )
			if ( preg_match( '|^'.$prefix.$ms_file.'$|', $file_name ) ) {
				$is_ms_file = true;
				break;
			}
		return $is_ms_file;
	}

	function is_not_ms_file( $file_name ) {
		return !$this->is_ms_file( $file_name );
	}
}

// run the CLI only if the file
// wasn't included
$included_files = get_included_files();
if ( $included_files[0] == __FILE__ ) {
	$makepot = new MakePOT;
	$count   = count( $argv );

	if ( $count >= 3 && $count <= 5 && in_array( $method = str_replace( '-', '_', $argv[1] ), get_class_methods( $makepot ) ) ) {
		$res = call_user_func(
			array( $makepot, $method ),
			realpath( $argv[2] ),
			isset( $argv[3] )? $argv[3] : null,
			isset( $argv[4] )? $argv[4] : null
		);

		if ( false === $res )
			fwrite( STDERR, "Couldn't generate POT file!\n" );
	}
	else {
		$usage  = "Usage: php makepot.php PROJECT DIRECTORY [OUTPUT]\n\n";
		$usage .= "Generate POT file from the files in DIRECTORY [OUTPUT]\n";
		$usage .= "Available projects: " . implode( ', ', $makepot->projects )."\n";
		fwrite( STDERR, $usage );
		exit(1);
	}
}
