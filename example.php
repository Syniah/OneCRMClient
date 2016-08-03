<?php
/**
 * Simple example of calling the 1CRM API
 */

//Load composer's autoloader if you haven't already
require_once 'vendor/autoload.php';

$endpoint = 'https://demo.example.com/service/v4/rest.php';

//See README for notes on how to enable debug output
$c = new OneCRM\Client($endpoint, true);
try {
    $c->login('demo', 'demo');
    echo "Connected to $endpoint successfully\n";
    echo 'Found '.count($c->getModules()). ' modules'."\n";
    //Find the first 10 accounts
    $response = $c->call(
        'Accounts',
        'get_entry_list',
        [
            'select_fields' => [
                'id',
                'name'
            ],
            'max_results' => 10
        ]
    );
    //Process the response
    foreach ($response->entry_list as $item) {
        foreach ($item->name_value_list as $field) {
            echo $field->name, ': ', $field->value . " ";
        }
        echo "\n";
    }
    echo "Request took ".$c->getLastRequestDuration()." sec\n";
    //Exception handling example - request for a non-existent module
    try {
        $response = $c->call('Bananas', 'get_entry_list');
    } catch (OneCRM\ModuleException $e) {
        echo "Sorry, we have no bananas\n";
    }
} catch (OneCRM\ConnectionException $e) {
    echo 'A connection error occurred: ' . $e->getMessage();
} catch (OneCRM\AuthException $e) {
    echo 'An authentication error occurred: ' . $e->getMessage();
} catch (OneCRM\ModuleException $e) {
    echo 'A module error occurred: ' . $e->getMessage();
} catch (OneCRM\Exception $e) {
    echo 'An error occurred: ' . get_class($e) . ': ' . $e->getMessage();
}
