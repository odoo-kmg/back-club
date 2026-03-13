<?php
return [
  'app' => [
    'debug' => true,

    // IMPORTANTE:
    // Como la API vive en https://TU_DOMINIO/api/..., el base_path debe ser '/api'
    'base_path' => '/api',
  ],

  'db' => [
    'host' => 'localhost',
    'name' => 'kmgcom_club_sorteos_poc',
    'user' => 'kmgcom_poc_user',
    'pass' => 'YzV2BtHRW?mr',
    'charset' => 'utf8mb4',
  ],

  'jwt' => [
    'secret' => 'eec2d31510adecbcbf0b4d39ff457ac1a630f6eb7270f44217203c5ea319bc19',
    'issuer' => 'club-puerto-azul',
    'audience' => 'club-puerto-azul-web',
    'ttl_seconds' => 60 * 60 * 8,
  ],

'cors' => [
  'allowed_origins' => [
    'http://localhost:5173',
    'http://127.0.0.1:5173',

    // opcional (vite preview)
    'http://localhost:4173',
    'http://127.0.0.1:4173',
  ],
  'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
  'allowed_methods' => ['GET', 'POST', 'PATCH', 'OPTIONS'],
],

  // API keys para integraciones externas (NO usar en el browser)
  'api_keys' => [
    'botmaker' => '623cd39b8a1da51886cc8b9704b0230febdd232ecdba089ad153323aa35fabff',
  ],

  // Token para herramientas temporales (hash.php). Cambia y luego borras tools/hash.php
  'tools' => [
    'hash_token' => 'f61562105657fdcdcb1c08f88d4632d84e4d8294dc99ed8883cef46b17dde484',
  ],
];