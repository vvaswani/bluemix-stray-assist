# Stray Assist

This repository accompanies the IBM developerWorks article. It's built with PHP, Silex 2.x and Bootstrap. It uses various services, including Bluemix Object Storage and Bluemix Cloudant. 

It delivers an application that lets citizens report the location of an injured stray, together with a photo and automatic location. Reports can be viewed by an administrator and located on a map.

The steps below assume that an Object Storage service and Cloudant service have been instantiated via the the Bluemix console, and that the user has a valid Google API key.

To deploy this application to your local development environment:

 * Clone the repository to your local system.
 * Run `composer update` to install all dependencies.
 * Create `config.php` with credentials for the various services. Use `config.php.sample` as an example.
 * Create an empty database in your Cloudant instance and add a design document to handle searches, as described in the article.
 * Define a virtual host pointing to the `public` directory, as described in the article.
 
To deploy this application to your Bluemix space:

 * Clone the repository to your local system.
 * Run `composer update` to install all dependencies.
 * Create `config.php` with credentials for the various services. Use `config.php.sample` as an example.
 * Create an empty database in your Cloudant instance and add a design document to handle searches, as described in the article.
  * Update `manifest.yml` with your custom hostname.
 * Push the application to Bluemix and bind Object Storage and Cloudant services to it, as described in the article.
 
A demo instance is available on Bluemix at [http://stray-assist.mybluemix.net](http://stray-assist.mybluemix.net).

###### NOTE: The demo instance is available on a public URL and you should be careful to avoid posting sensitive or confidential documents to it. Use the "System Reset" function in the footer to delete all data and restore the system to its default state.