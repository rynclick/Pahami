<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// The simulator is the quickest way to see the whole agent.
$routes->addRedirect('/', 'demo/chat');

// Protected with basic auth when DEMO_PASSWORD is set (needed once the app is reachable publicly).
$routes->group('demo', ['filter' => ['demoauth', 'uilocale']], static function (RouteCollection $routes): void {
    $routes->get('/', 'Demo::index');
    $routes->post('/', 'Demo::upload', ['filter' => 'csrf']);
    $routes->get('sample', 'Demo::sample');
    $routes->get('(:num)', 'Demo::show/$1');
    $routes->get('(:num)/file', 'Demo::file/$1');
    $routes->get('(:num)/translate/(:num)', 'Demo::translate/$1/$2');

    // WhatsApp simulator: the full agent without a Fonnte account.
    $routes->get('chat', 'DemoChat::index');
    $routes->get('chat/state', 'DemoChat::poll');
    $routes->get('chat/translate/(:num)', 'DemoChat::translate/$1');
    $routes->post('chat/send', 'DemoChat::send', ['filter' => 'csrf']);
    $routes->post('chat/sample', 'DemoChat::sample', ['filter' => 'csrf']);
    $routes->post('chat/reminder', 'DemoChat::reminder', ['filter' => 'csrf']);
    $routes->post('chat/reset', 'DemoChat::reset', ['filter' => 'csrf']);
});

// Court staff view; same login as /demo. Real WhatsApp users, simulator and web uploads kept apart.
$routes->group('admin', ['filter' => ['demoauth', 'uilocale']], static function (RouteCollection $routes): void {
    $routes->get('/', 'Admin::index');
    $routes->get('document/(:num)', 'Admin::document/$1');
    $routes->get('document/(:num)/file', 'Admin::file/$1');
    $routes->get('message/(:num)/translate', 'Admin::translate/$1');
});

// Page language (Indonesian / English) for /demo and /admin.
$routes->get('lang/(:segment)', 'Lang::set/$1');

// Fonnte requires the webhook URL to accept both POST and GET.
$routes->match(['GET', 'POST'], 'webhook/fonnte', 'Webhook\Fonnte::receive');
