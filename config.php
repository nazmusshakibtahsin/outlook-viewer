<?php
// ==== এখানে নিজের তথ্য বসান ====
return [
  'db_host' => 'localhost',
  'db_name' => 'outlook_viewer',
  'db_user' => 'root',
  'db_pass' => '',
  // যেকোনো লম্বা গোপন লেখা দিন (৩২+ অক্ষর)। মেইলবক্স যোগ করার পর আর বদলাবেন না।
  'encryption_key' => 'CHANGE_ME_to_a_long_random_secret_string_1234567890',
  'mailgen_endpoint' => 'https://mailgen.shop/api/get-inbox',
  'rate_limit_per_min' => 20,
  'cache_minutes' => 60,
];
