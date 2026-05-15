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
	'combodo-hybridauth/2.1.2',
	array(
		// Identification
		//
		'label' => 'oAuth/OpenID authentication',
		'category' => 'business',

		// Setup
		//
		'dependencies' => array(
			'combodo-oauth2-client/1.0.10',
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

        // Security
        'delegated_authentication_endpoints' => [
            'landing.php',
        ],

		// Default settings
		//
		'settings' => array(
            'debug' => false,
            'synchronize_user' => false,
            'synchronize_contact' => false,
            'default_organization' => '', //you must fill it here or at provider level for contact provisioning
            'default_profiles' => ['Portal User', 'Configuration Manager'], //must be set here or at provider level for user provisioning
			'default_allowed_orgs' => [], //leave it empty here AND at provider level to disable allowed orgs provisioning
			'providers' => array(
			  'Keycloak' =>
              array (
                'keys' =>
                array (
                  'id' => 'my-clientid',
                  'secret' => 'my-secret',
                ),
                'enabled' => false,
                'debug' => true,
                'url' => 'keycloak_url',
                'realm' => 'my_realm',
                'label' => 'keycloak',
                'tooltip' => 'click to authenticate through keycloak',
                'icon_url' => '',
                'synchronize_contact' => false,
                'refresh_existing_contact' => false,
				'default_organization' => null,
                'synchronize_user' => false,
                'refresh_existing_user' => false,
				'default_profiles' => null,
                'logout_before_disconnect' => false, //to logout from Keycloak when quiting iTop

				//for advanced provisioning
                'provide_all_getuserprofile_output' => false, //to pass all IdP GetUserProfile fields

				'org_idp_key' => 'organization', //field fetched in IdP GetUserProfile answer
				'org_oql_search_field' => 'name', //iTop field to reconciliate org (defaut name)

				'profiles_idp_key' => null, //advanced profile provisioning
				'profiles_idp_separator' => null, //advanced profile provisioning
				'groups_to_profiles' => null, //advanced profile provisioning

				'allowed_orgs_idp_key' => null, //advanced allowed orgs provisioning
				'allowed_orgs_idp_separator' => null, //advanced allowed orgs provisioning
				'allowed_orgs_oql_search_field' => null, //advanced allowed orgs provisioning
				'groups_to_orgs' => null, //advanced allowed orgs provisioning
              ),
				'Google' => array(
					'enabled' => false,
					'keys' => array(
						'id'     => 'your-google-client-id',
						'secret' => 'your-google-client-secret'
					),
				),
			),
		),
	)
);
