<?php
/**
 * Simple example of calling the 1CRM API
 */

//Load composer's autoloader if you haven't already
require 'vendor/autoload.php';

$c = new OneCRM\Client('https://1crm.example.com/service/v4/rest.php', false);
try {
    $c->login('demo', 'demo');
    echo $c->listModules();
    //Find the first 10 accounts
    $response = $c->call(
        'Accounts',
        'get_entry_list',
        array('select_fields' => array('id', 'name'), 'max_results' => 10)
    );
    //Process the response
    foreach ($response->entry_list as $item) {
        foreach ($item->name_value_list as $field) {
            echo $field->name, ': ', $field->value . " ";
        }
        echo "\n";
    }
} catch (OneCRM\Exception $e) {
    echo 'An error occurred: ', $e->getMessage();
}
