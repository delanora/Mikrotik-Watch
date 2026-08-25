<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Definição de Rotas
 *
 * Define todas as rotas da aplicação.
 * Formato: [METHOD, path, controller@action]
 *
 * Métodos suportados: GET, POST, PUT, DELETE
 */

return [
    // ─── Página Inicial ───────────────────────────────────────────────────────
    ['GET',  '/',                          'DashboardController@index'],

    // ─── Autenticação ─────────────────────────────────────────────────────────
    ['GET',  '/login',                     'AuthController@loginForm'],
    ['POST', '/login',                     'AuthController@login'],
    ['GET',  '/logout',                    'AuthController@logout'],

    // ─── Dashboard ────────────────────────────────────────────────────────────
    ['GET',  '/dashboard',                 'DashboardController@index'],
    ['GET',  '/dashboard/stats',           'DashboardController@stats'],

    // ─── Clientes ─────────────────────────────────────────────────────────────
    ['GET',  '/clients',                   'ClientController@index'],
    ['GET',  '/clients/create',            'ClientController@create'],
    ['POST', '/clients',                   'ClientController@store'],
    ['GET',  '/clients/{id}/edit',         'ClientController@edit'],
    ['POST',  '/clients/{id}',             'ClientController@update'],
    ['POST',  '/clients/{id}/delete',      'ClientController@destroy'],

    // ─── Equipamentos Mikrotik ─────────────────────────────────────────────────
    ['GET',  '/mikrotiks',                 'MikrotikController@index'],
    ['GET',  '/mikrotiks/create',          'MikrotikController@create'],
    ['POST', '/mikrotiks/store',           'MikrotikController@store'],
    ['GET',  '/mikrotiks/{id}',            'MikrotikController@show'],
    ['GET',  '/mikrotiks/{id}/edit',       'MikrotikController@edit'],
    ['POST', '/mikrotiks/{id}/update',     'MikrotikController@update'],
    ['POST', '/mikrotiks/{id}/delete',     'MikrotikController@delete'],
    ['POST', '/mikrotiks/{id}/test',       'MikrotikController@testConnection'],

    // ─── Interfaces ───────────────────────────────────────────────────────────
    ['GET',  '/mikrotiks/{id}/interfaces', 'InterfaceController@index'],
    ['GET',  '/interfaces/{id}/traffic',   'InterfaceController@trafficData'],

    // ─── API (para AJAX/JavaScript) ───────────────────────────────────────────
    ['GET',  '/api/stats',                 'ApiController@stats'],
    ['GET',  '/api/mikrotiks',             'ApiController@mikrotiks'],
    ['GET',  '/api/traffic/{id}',          'ApiController@trafficData'],

    // ─── Configurações ────────────────────────────────────────────────────────
    ['GET',  '/settings',                  'SettingsController@index'],
    ['POST', '/settings/update',           'SettingsController@update'],
];
