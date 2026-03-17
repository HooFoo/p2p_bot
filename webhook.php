<?php

require_once __DIR__ . '/src/telegram.php';
require_once __DIR__ . '/src/OrderManager.php';
$config = include __DIR__ . '/config.php';

// Подключение к БД
try {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage());
    exit;
}

$orderManager = new OrderManager($pdo, $config);

// Получение данных от Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit;
}

$message = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;

$chatId = null;
$userId = null;
$text = null;
$data = null;

if ($message) {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $firstName = $message['from']['first_name'] ?? '';
    $text = $message['text'] ?? '';
} elseif ($callbackQuery) {
    $chatId = $callbackQuery['message']['chat']['id'];
    $userId = $callbackQuery['from']['id'];
    $username = $callbackQuery['from']['username'] ?? '';
    $firstName = $callbackQuery['from']['first_name'] ?? '';
    $data = $callbackQuery['data'];
}

if (!$chatId) exit;

// Регистрация или получение пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, first_name) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $username, $firstName ?? '']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
} else {
    // Обновим инфо если поменялось
    $stmt = $pdo->prepare("UPDATE users SET username = ?, first_name = ? WHERE id = ?");
    $stmt->execute([$username, $firstName, $user['id']]);
}

$mainMenu = [
    'inline_keyboard' => [
        [['text' => '📦 Мои заявки', 'callback_data' => 'my_orders']],
        [['text' => '🔄 Хочу обменять', 'callback_data' => 'create_order']],
        [['text' => '🔍 Открытые заявки', 'callback_data' => 'view_open_orders']],
        [['text' => '📜 История', 'callback_data' => 'history']]
    ]
];

// Сброс по команде /start
if ($text === '/start') {
    $pdo->prepare("UPDATE users SET state = 'IDLE', step_data = NULL WHERE id = ?")->execute([$user['id']]);
    sendMessage($chatId, "Привет, <b>" . ($user['first_name'] ?: 'Друг') . "</b>! Это P2P бот для обмена валют. Что выберете?", $mainMenu);
    exit;
}

$state = $user['state'];
$stepData = $user['step_data'] ? json_decode($user['step_data'], true) : [];

// --- ОБРАБОТКА CALLBACK ---
if ($callbackQuery) {
    answerCallbackQuery($callbackQuery['id']);
    
    // Переход к просмотру всех заявок
    if ($data === 'view_open_orders') {
        $pdo->prepare("UPDATE users SET state = 'WAIT_VIEW_CURRENCY' WHERE id = ?")->execute([$user['id']]);
        $buttons = [];
        foreach (array_chunk($config['currencies'], 2) as $chunk) {
            $row = [];
            foreach ($chunk as $curr) { $row[] = ['text' => "Смотреть $curr", 'callback_data' => "viewcurr_$curr"]; }
            $buttons[] = $row;
        }
        $buttons[] = [['text' => '◀️ Назад', 'callback_data' => 'main_menu']];
        sendMessage($chatId, "Выберите валюту, которую продают другие пользователи:", ['inline_keyboard' => $buttons]);
        exit;
    }

    // Показ заявок по выбранной валюте
    if (strpos($data, 'viewcurr_') === 0 && $state === 'WAIT_VIEW_CURRENCY') {
        $currency = str_replace('viewcurr_', '', $data);
        $orders = $orderManager->findByCurrency($user['id'], $currency);
        
        $pdo->prepare("UPDATE users SET state = 'IDLE' WHERE id = ?")->execute([$user['id']]);

        if (empty($orders)) {
            sendMessage($chatId, "Активных заявок по валюте <b>$currency</b> пока нет.", $mainMenu);
        } else {
            $btns = [];
            foreach ($orders as $o) {
                $buyArr = json_decode($o['buy_currencies'], true);
                $buyStr = implode(', ', $buyArr);
                $btns[] = [['text' => "👤 {$o['first_name']} ({$o['amount']} $currency -> $buyStr)", 'callback_data' => "respond_{$o['id']}"]];
            }
            $btns[] = [['text' => '◀️ В главное меню', 'callback_data' => 'main_menu']];
            sendMessage($chatId, "Найдено " . count($orders) . " заявок по <b>$currency</b>:", ['inline_keyboard' => $btns]);
        }
        exit;
    }

    // Переход в создание заявки
    if ($data === 'create_order') {
        $pdo->prepare("UPDATE users SET state = 'WAIT_SELL_CURRENCY', step_data = NULL WHERE id = ?")->execute([$user['id']]);
        $buttons = [];
        foreach (array_chunk($config['currencies'], 3) as $chunk) {
            $row = [];
            foreach ($chunk as $curr) { $row[] = ['text' => $curr, 'callback_data' => "sell_$curr"]; }
            $buttons[] = $row;
        }
        sendMessage($chatId, "Какую валюту вы хотите <b>ОТДАТЬ</b>?", ['inline_keyboard' => $buttons]);
        exit;
    }

    // Выбор валюты ОТДАТЬ
    if (strpos($data, 'sell_') === 0 && $state === 'WAIT_SELL_CURRENCY') {
        $currency = str_replace('sell_', '', $data);
        $stepData['sell_currency'] = $currency;
        $pdo->prepare("UPDATE users SET state = 'WAIT_AMOUNT', step_data = ? WHERE id = ?")->execute([json_encode($stepData), $user['id']]);
        sendMessage($chatId, "Введите объем <b>$currency</b> который у вас есть (числом):");
        exit;
    }

    // Выбор валюты ПОЛУЧИТЬ (множественный выбор)
    if (strpos($data, 'buy_') === 0 && $data !== 'buy_done' && $state === 'WAIT_BUY_CURRENCY') {
        $currency = str_replace('buy_', '', $data);
        if (!isset($stepData['buy_currencies'])) $stepData['buy_currencies'] = [];
        
        if (in_array($currency, $stepData['buy_currencies'])) {
            $stepData['buy_currencies'] = array_values(array_diff($stepData['buy_currencies'], [$currency]));
        } else {
            $stepData['buy_currencies'][] = $currency;
        }
        
        $pdo->prepare("UPDATE users SET step_data = ? WHERE id = ?")->execute([json_encode($stepData), $user['id']]);
        
        $buttons = [];
        foreach (array_chunk($config['currencies'], 2) as $chunk) {
            $row = [];
            foreach ($chunk as $curr) {
                $check = in_array($curr, $stepData['buy_currencies']) ? " ✅" : "";
                $row[] = ['text' => $curr . $check, 'callback_data' => "buy_$curr"];
            }
            $buttons[] = $row;
        }
        $buttons[] = [['text' => '✅ ГОТОВО (минимум одна)', 'callback_data' => 'buy_done']];
        
        editMessageText($chatId, $callbackQuery['message']['message_id'], "Выберите валюты которые хотите <b>ПОЛУЧИТЬ</b>:", ['inline_keyboard' => $buttons]);
        exit;
    }

    // Завершение создания
    if ($data === 'buy_done' && $state === 'WAIT_BUY_CURRENCY') {
        if (empty($stepData['buy_currencies'])) {
            sendMessage($chatId, "Ошибка: выберите хотя бы одну валюту!");
            exit;
        }
        
        // Создаем заявку
        $orderManager->createOrder($user['id'], $stepData['sell_currency'], $stepData['amount'], $stepData['buy_currencies']);
        $pdo->prepare("UPDATE users SET state = 'IDLE', step_data = NULL WHERE id = ?")->execute([$user['id']]);
        
        // Убираем кнопки из сообщения с выбором валют
        editMessageText($chatId, $callbackQuery['message']['message_id'], "✅ <b>Заявка создана!</b>\n\nИщем подходящие предложения...");
        
        // Мачинг
        $matches = $orderManager->findMatches($user['id'], $stepData['sell_currency'], $stepData['amount'], $stepData['buy_currencies']);
        
        if (empty($matches)) {
            sendMessage($chatId, "Пока подходящих заявок нет. Вам придет уведомление, когда кто-то откликнется на вашу заявку.", $mainMenu);
        } else {
            $matchButtons = [];
            foreach ($matches as $m) {
                $matchButtons[] = [['text' => "👤 {$m['first_name']} ({$m['sell_currency']} -> {$m['amount']})", 'callback_data' => "respond_{$m['id']}"]];
            }
            $matchButtons[] = [['text' => '◀️ В главное меню', 'callback_data' => 'main_menu']];
            sendMessage($chatId, "Найдены подходящие заявки (до 10):", ['inline_keyboard' => $matchButtons]);
        }
        exit;
    }

    // Отклик на чужую заявку
    if (strpos($data, 'respond_') === 0) {
        $orderId = (int)str_replace('respond_', '', $data);
        // Создаем запись в matches
        $stmt = $pdo->prepare("INSERT INTO matches (order_id, responder_id) VALUES (?, ?)");
        $stmt->execute([$orderId, $user['id']]);
        $matchId = $pdo->lastInsertId();

        // Уведомление создателю
        $stmt = $pdo->prepare("SELECT o.*, u.telegram_id FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $orderInfo = $stmt->fetch();

        sendMessage($orderInfo['telegram_id'], "🔔 На вашу заявку <b>#$orderId</b> откликнулся пользователь <b>{$user['first_name']}</b>!", [
            'inline_keyboard' => [
                [['text' => '✅ ПРИНЯТЬ СДЕЛКУ', 'callback_data' => "accept_$matchId"]]
            ]
        ]);
        
        sendMessage($chatId, "Ваш запрос отправлен. Ожидайте подтверждения.");
        exit;
    }

    // Подтверждение сделки
    if (strpos($data, 'accept_') === 0) {
        $matchId = (int)str_replace('accept_', '', $data);
        // Получаем инфу
        $stmt = $pdo->prepare("SELECT m.*, o.sell_currency, o.amount, o.user_id as owner_id, u.telegram_id as responder_tid, u.username as responder_un, u.first_name as responder_name FROM matches m JOIN orders o ON m.order_id = o.id JOIN users u ON m.responder_id = u.id WHERE m.id = ?");
        $stmt->execute([$matchId]);
        $mInfo = $stmt->fetch();

        $pdo->prepare("UPDATE matches SET status = 'accepted' WHERE id = ?")->execute([$matchId]);
        $pdo->prepare("UPDATE orders SET status = 'closed', is_active = FALSE WHERE id = ?")->execute([$mInfo['order_id']]);

        $owner = $pdo->query("SELECT * FROM users WHERE id = {$mInfo['owner_id']}")->fetch();

        // Обмен контактами
        $ownerContact = $owner['username'] ? "@" . $owner['username'] : "ID: " . $owner['telegram_id'];
        $respContact = $mInfo['responder_un'] ? "@" . $mInfo['responder_un'] : "ID: " . $mInfo['responder_tid'];

        sendMessage($mInfo['responder_tid'], "🎉 <b>Сделка принята!</b>\n\nКонтакт продавца: <b>$ownerContact</b> ({$owner['first_name']})\n\nДоговоритесь об обмене. После того как обмен будет <b>физически совершен</b>, создатель заявки сможет закрыть её или изменить остатки.");
        
        sendMessage($chatId, "🎉 <b>Контакты покупателя:</b>\n<b>$respContact</b> ({$mInfo['responder_name']})\n\nСвяжитесь для совершения обмена.\n\nКогда вы <b>завершите</b> обмен, выберите действие для вашей заявки ниже:");

        // После сделки - управление остатком
        sendMessage($chatId, "Что сделать с заявкой #{$mInfo['order_id']} после обмена?", [
            'inline_keyboard' => [
                [['text' => '✅ Обмен совершен (Закрыть)', 'callback_data' => "close_{$mInfo['order_id']}"]],
                [['text' => '✏️ Частичный обмен (Изменить объем)', 'callback_data' => "edit_amt_{$mInfo['order_id']}"]],
                [['text' => '⚠️ Оставить как есть (Неудачно)', 'callback_data' => 'main_menu']]
            ]
        ]);
        exit;
    }

    // Управление моими заявками
    if ($data === 'my_orders') {
        $orders = $orderManager->getUserOrders($user['id']);
        if (empty($orders)) {
            sendMessage($chatId, "У вас нет активных заявок.", $mainMenu);
        } else {
            $txt = "Ваши активные заявки:\n";
            $btns = [];
            foreach ($orders as $o) {
                $txt .= "• #{$o['id']} {$o['sell_currency']} -> {$o['amount']}\n";
                $btns[] = [['text' => "Закрыть #{$o['id']}", 'callback_data' => "close_{$o['id']}"]];
            }
            $btns[] = [['text' => '◀️ Назад', 'callback_data' => 'main_menu']];
            sendMessage($chatId, $txt, ['inline_keyboard' => $btns]);
        }
        exit;
    }

    if ($data === 'main_menu') {
        sendMessage($chatId, "Главное меню:", $mainMenu);
        exit;
    }

    // История
    if ($data === 'history') {
        $history = $orderManager->getHistory($user['id']);
        if (empty($history)) {
            sendMessage($chatId, "Ваша история пока пуста. Закройте хотя бы одну заявку, чтобы она появилась здесь.", $mainMenu);
        } else {
            $txt = "<b>Ваша история (последние 20):</b>\n\n";
            foreach ($history as $h) {
                $status = ($h['status'] === 'closed') ? "✅" : "⚠️";
                $txt .= "$status #{$h['id']} {$h['sell_currency']} -> {$h['amount']} (" . date('d.m H:i', strtotime($h['updated_at'])) . ")\n";
            }
            sendMessage($chatId, $txt, $mainMenu);
        }
        exit;
    }

    // Закрытие заявки
    if (strpos($data, 'close_') === 0) {
        $orderId = (int)str_replace('close_', '', $data);
        $orderManager->closeOrder($orderId, $user['id']);
        sendMessage($chatId, "✅ Заявка #$orderId закрыта.", $mainMenu);
        exit;
    }

    // Редактирование объема (начало)
    if (strpos($data, 'edit_amt_') === 0) {
        $orderId = (int)str_replace('edit_amt_', '', $data);
        $stepData = ['edit_order_id' => $orderId];
        $pdo->prepare("UPDATE users SET state = 'WAIT_NEW_AMOUNT', step_data = ? WHERE id = ?")->execute([json_encode($stepData), $user['id']]);
        sendMessage($chatId, "Введите <b>новый оставшийся объем</b> для заявки #$orderId (число):");
        exit;
    }
}

// --- ОБРАБОТКА ТЕКСТА (FSM) ---
if ($text && $state === 'WAIT_NEW_AMOUNT') {
    $amount = str_replace(',', '.', $text);
    if (!is_numeric($amount) || $amount < 0) {
        sendMessage($chatId, "Пожалуйста, введите корректное число (0 или больше):");
        exit;
    }
    
    $orderId = $stepData['edit_order_id'];
    $orderManager->updateAmount($orderId, $user['id'], (float)$amount);
    
    $pdo->prepare("UPDATE users SET state = 'IDLE', step_data = NULL WHERE id = ?")->execute([$user['id']]);
    sendMessage($chatId, "✅ Объем заявки #$orderId изменен на $amount.", $mainMenu);
    exit;
}
if ($text && $state === 'WAIT_AMOUNT') {
    $amount = str_replace(',', '.', $text);
    if (!is_numeric($amount) || $amount <= 0) {
        sendMessage($chatId, "Пожалуйста, введите корректное число больше нуля:");
        exit;
    }
    
    $stepData['amount'] = (float)$amount;
    $pdo->prepare("UPDATE users SET state = 'WAIT_BUY_CURRENCY', step_data = ? WHERE id = ?")->execute([json_encode($stepData), $user['id']]);
    
    $buttons = [];
    foreach (array_chunk($config['currencies'], 2) as $chunk) {
        $row = [];
        foreach ($chunk as $curr) { $row[] = ['text' => $curr, 'callback_data' => "buy_$curr"]; }
        $buttons[] = $row;
    }
    sendMessage($chatId, "Теперь выберите валюты которые хотите <b>ПОЛУЧИТЬ</b> (одну или несколько):", ['inline_keyboard' => $buttons]);
    exit;
}
