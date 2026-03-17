<?php

/**
 * Отправка запроса к Telegram API через cURL
 */
function sendTelegramRequest($method, $params = [], $token = '') {
    static $config = null;
    if (empty($token)) {
        if ($config === null) {
            $config = include __DIR__ . '/../config.php';
        }
        $token = $config['telegram_token'];
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Telegram API Error: " . $error);
        return false;
    }
    
    return json_decode($response, true);
}

/**
 * Отправка сообщения
 */
function sendMessage($chatId, $text, $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($replyMarkup) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    
    return sendTelegramRequest('sendMessage', $params);
}

/**
 * Редактирование сообщения
 */
function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($replyMarkup) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    
    return sendTelegramRequest('editMessageText', $params);
}

/**
 * Ответ на callback query
 */
function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false) {
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text) {
        $params['text'] = $text;
        $params['show_alert'] = $showAlert;
    }
    return sendTelegramRequest('answerCallbackQuery', $params);
}
