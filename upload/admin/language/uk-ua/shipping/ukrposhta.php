<?php
// Heading
$_['heading_title']              = 'Укрпошта — доставка';

// Text
$_['text_home']                  = 'Головна';
$_['text_extension']             = 'Розширення';
$_['text_shipments']             = 'Відправлення';
$_['text_edit']                  = 'Налаштування Укрпошти';
$_['text_success']               = 'Налаштування збережено.';
$_['text_setup_ok']              = 'Таблиці, події та cron встановлено.';
$_['text_setup_hint']            = 'Перший запуск: натисніть «Встановити», щоб створити таблиці, події та завдання cron. Потім синхронізуйте області.';
$_['text_saved']                 = ' (збережено)';
$_['text_test_ok']               = 'З\'єднання успішне — Bearer прийнято.';
$_['text_test_fail']             = 'Помилка з\'єднання:';
$_['text_sync_ok']               = 'Синхронізовано областей: %d.';
$_['text_quote_ok']              = 'Тестовий тариф: %.2f грн.';
$_['text_quote_fail']            = 'Не вдалося отримати тариф:';
$_['text_disabled']              = 'Вимкнено';
$_['text_all_zones']             = 'Усі зони';
$_['text_none']                  = 'Немає';
$_['text_theme_auto']            = 'Авто (тема сайту)';
$_['text_theme_light']           = 'Світла';
$_['text_theme_dark']            = 'Темна';

// Tabs
$_['tab_credentials']            = 'Ключі доступу';
$_['tab_sender']                 = 'Відправник';
$_['tab_behaviour']              = 'Поведінка';
$_['tab_appearance']             = 'Вигляд віджета';

// Buttons
$_['button_setup']               = 'Встановити';
$_['button_sync_regions']        = 'Синхронізувати області';
$_['button_test']                = 'Перевірити з\'єднання';
$_['button_quote']               = 'Тестовий тариф';

// Entries
$_['entry_sandbox']              = 'Тестове середовище (sandbox)';
$_['entry_bearer']               = 'Bearer eCom';
$_['entry_bearer_ph']            = 'Вставте Bearer-ключ';
$_['entry_token']                = 'Token (user token)';
$_['entry_token_ph']             = 'Вставте token';
$_['entry_tracking_bearer']      = 'Bearer StatusTracking';
$_['entry_optional']             = 'Необов\'язково';
$_['entry_sender_uuid']          = 'UUID відправника (client)';
$_['entry_sender_postcode']      = 'Індекс відправника';
$_['entry_status']               = 'Увімкнути метод доставки';
$_['entry_service_type']         = 'Тип послуги';
$_['entry_default_cost']         = 'Тариф за замовчуванням, грн';
$_['entry_cod']                  = 'Накладений платіж (післяоплата) для замовлень «Оплата при отриманні»';
$_['entry_paid_by_recipient']    = 'Доставку оплачує отримувач';
$_['entry_auto_ttn']             = 'Автостворення відправлення при статусі';
$_['entry_geo_zone']             = 'Гео-зона';
$_['entry_tax_class']            = 'Клас податку';
$_['entry_sort_order']           = 'Порядок';
$_['entry_accent']               = 'Акцентний колір';
$_['entry_radius']               = 'Заокруглення, px';
$_['entry_theme']                = 'Тема віджета';

// Help
$_['help_bearer']                = 'Видається Укрпоштою після підписання договору (кабінет eCom). Зберігається зашифровано.';
$_['help_token']                 = 'User token для запису відправлень (?token=…). Зберігається зашифровано.';
$_['help_tracking_bearer']       = 'Окремий Bearer для трекінгу. Якщо порожньо — використовується Bearer eCom.';
$_['help_sender_uuid']           = 'UUID клієнта-відправника з кабінету eCom (створюється один раз).';
$_['help_sender_postcode']       = 'Поштовий індекс складу відправлення — потрібен для розрахунку тарифу.';
$_['help_default_cost']          = 'Використовується, коли API недоступний або немає індексу отримувача.';
$_['help_auto_ttn']              = 'Коли замовлення переходить у цей статус — відправлення створюється автоматично.';

// Errors
$_['error_permission']           = 'У вас немає прав змінювати цей модуль.';
$_['error_bearer_empty']         = 'Не вказано Bearer-ключ.';
$_['error_sender_postcode_empty']= 'Не вказано індекс відправника.';
