<?php
/**
 * @copyright   Copyright (C) 2010-2024 Combodo SAS
 * @license     https://www.combodo.com/documentation/combodo-software-license.html
 *
 */

//
// iTop module definition file
//
SetupWebPage::AddModule(
	__FILE__, // Path to the current file, all other file names are relative to the directory containing this file
	'combodo-hybridauth/1.2.4',
	array(
		// Identification
		//
		'label' => 'oAuth/OpenID authentication',
		'category' => 'business',

		// Setup
		//
		'dependencies' => array(
			'combodo-oauth2-client/1.0.0',
		),
		'mandatory' => false,
		'visible' => true,

		// Components
		//
		'datamodel' => array(
			'model.combodo-hybridauth.php',
			'vendor/autoload.php',
			'src/HybridAuthLoginExtension.php',
			'src/Config.php',
		),
		'webservice' => array(

		),
		'data.struct' => array(
			// add your 'structure' definition XML files here,
		),
		'data.sample' => array(
			// add your sample data XML files here,
		),

		// Documentation
		//
		'doc.manual_setup' => '', // hyperlink to manual setup documentation, if any
		'doc.more_information' => '', // hyperlink to more information, if any

		// Default settings
		//
		'settings' => array(
            'debug' => false,
            'synchronize_user' => false,
            'synchronize_contact' => false,
            'default_organization' => '',
            'default_profile' => 'Portal User',
			'providers' => array(
				'Google' => array(
					'enabled' => true,
					'keys' => array(
						'id'     => 'your-google-client-id',
						'secret' => 'your-google-client-secret'
					),
				),
				'Twitter' => array(
					'enabled' => false,     //Optional: indicates whether to enable or disable Twitter adapter. Defaults to false
					'keys' => array(
						'key'    => '...', //Required: your Twitter consumer key
						'secret' => '...'  //Required: your Twitter consumer secret
					),
				),
				'Facebook' => array(
					'enabled' => false,
					'keys' => array(
						'id'  => '...',
						'secret' => '...',
					),
				),
			),
		),
	)
);
