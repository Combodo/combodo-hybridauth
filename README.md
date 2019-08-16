# oAuth/OpenID

The support of the most common *oAuth* or *OpenID* providers (Facebook, Google, Twitter, LinkedIn...) is implemented thanks to the [HybridAuth library](https://hybridauth.github.io/). This library provides a unified interface to identify a user and retrieve profile information about this user for all providers.

## combodo-bydridauth extension

This extension is a quick and dirty *proof of concept* of using HybridAuth with *one* hardcoded provider (tested successively with GitHub and LinkedIn).

### Remaining work to be done

 - De-hardcode the configuration and allow for several sign-in buttons using the same provider to enable login with GitHub/Google/Twitter...
 - Given the information available in the `User\Profile` class (which seems unified accross all providers) it should be possible to implement an automatic provisioning for such user accounts. 

------------------------------

# Login form

POC of the login form to be customized using a **twig** template for the whole form (potentially replaceable by an extension / or a `module_design` definition), plus the ability for each extension to provide its own twig template for its button.

In order to use this in iTop, the twig system initialisation should (must ?) be factorized to support a common (and maintainable) list of placeholders (`app_root_url`, etc.).

The limitation is that the twig system does not have the same flexibility as the `WebPage` class regarding injection of scripts or stylesheets. This means that the template MUST provide extension points in its header for CSS and JS if we want to support this ability.

### Remaining work to be done

 - Refactoring of the twig system initialization
 - Proper design of the default twig template with its extension points (the button may not be enough). Can we make the login/passord form part optional ?
 - `module_design` definition or a similar mechanism to enable the replacement of the whole template.
 - Support for templates inside extensions (limitation of the default FileSystemLoader it seems).
 - Redesign the `iLoginExtension` interface to provide clearer semantics for the methods, document the login flow and the expected return types.
