<?php
// Heading
$_['heading_title']              = 'Ukrposhta Shipping';

// Text
$_['text_home']                  = 'Home';
$_['text_extension']             = 'Extensions';
$_['text_shipments']             = 'Shipments';
$_['text_edit']                  = 'Ukrposhta Settings';
$_['text_success']               = 'Settings saved.';
$_['text_setup_ok']              = 'Tables, events and cron installed.';
$_['text_setup_hint']            = 'First run: click "Install" to create tables, events and cron jobs. Then sync regions.';
$_['text_saved']                 = ' (saved)';
$_['text_test_ok']               = 'Connection OK — Bearer accepted.';
$_['text_test_fail']             = 'Connection failed:';
$_['text_sync_ok']               = 'Regions synced: %d.';
$_['text_quote_ok']              = 'Test tariff: %.2f UAH.';
$_['text_quote_fail']            = 'Could not fetch tariff:';
$_['text_disabled']              = 'Disabled';
$_['text_all_zones']             = 'All zones';
$_['text_none']                  = 'None';
$_['text_theme_auto']            = 'Auto (site theme)';
$_['text_theme_light']           = 'Light';
$_['text_theme_dark']            = 'Dark';

// Tabs
$_['tab_credentials']            = 'Credentials';
$_['tab_sender']                 = 'Sender';
$_['tab_behaviour']              = 'Behaviour';
$_['tab_appearance']             = 'Widget appearance';

// Buttons
$_['button_setup']               = 'Install';
$_['button_sync_regions']        = 'Sync regions';
$_['button_test']                = 'Test connection';
$_['button_quote']               = 'Test tariff';

// Entries
$_['entry_sandbox']              = 'Sandbox (test environment)';
$_['entry_bearer']               = 'eCom Bearer';
$_['entry_bearer_ph']            = 'Paste Bearer key';
$_['entry_token']                = 'Token (user token)';
$_['entry_token_ph']             = 'Paste token';
$_['entry_tracking_bearer']      = 'StatusTracking Bearer';
$_['entry_optional']             = 'Optional';
$_['entry_sender_uuid']          = 'Sender client UUID';
$_['entry_sender_postcode']      = 'Sender postcode';
$_['entry_status']               = 'Enable shipping method';
$_['entry_service_type']         = 'Service type';
$_['entry_default_cost']         = 'Default cost, UAH';
$_['entry_cod']                  = 'Cash on delivery for "pay on delivery" orders';
$_['entry_paid_by_recipient']    = 'Delivery paid by recipient';
$_['entry_auto_ttn']             = 'Auto-create shipment on status';
$_['entry_geo_zone']             = 'Geo zone';
$_['entry_tax_class']            = 'Tax class';
$_['entry_sort_order']           = 'Sort order';
$_['entry_accent']               = 'Accent colour';
$_['entry_radius']               = 'Corner radius, px';
$_['entry_theme']                = 'Widget theme';

// Help
$_['help_bearer']                = 'Issued by Ukrposhta after signing the contract (eCom cabinet). Stored encrypted.';
$_['help_token']                 = 'User token for shipment writes (?token=…). Stored encrypted.';
$_['help_tracking_bearer']       = 'Separate Bearer for tracking. If empty, the eCom Bearer is used.';
$_['help_sender_uuid']           = 'Sender client UUID from the eCom cabinet (created once).';
$_['help_sender_postcode']       = 'Dispatch post office index — required for tariff calculation.';
$_['help_default_cost']          = 'Used when the API is unavailable or the recipient index is unknown.';
$_['help_auto_ttn']              = 'When an order reaches this status the shipment is created automatically.';

// Errors
$_['error_permission']           = 'You do not have permission to modify this module.';
$_['error_bearer_empty']         = 'Bearer key is not set.';
$_['error_sender_postcode_empty']= 'Sender postcode is not set.';
