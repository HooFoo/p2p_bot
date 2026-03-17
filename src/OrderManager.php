<?php

require_once __DIR__ . '/CurrencyRates.php';

class OrderManager {
    private $pdo;
    private $config;

    public function __construct($pdo, $config) {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    public function findMatches($userId, $sellCurrency, $amount, $buyCurrencies) {
        $buyCurrenciesList = is_array($buyCurrencies) ? $buyCurrencies : json_decode($buyCurrencies, true);
        
        if (empty($buyCurrenciesList)) {
            return [];
        }

        // Эквивалент текущей заявки в USD для сравнения объемов
        $userUsdValue = CurrencyRates::toUsd($amount, $sellCurrency);
        $minUsd = $userUsdValue * 0.5; // Показывать тех, кто покрывает хотя бы половину? Или полностью? По условию - покрывает по объему.

        $placeholders = implode(',', array_fill(0, count($buyCurrenciesList), '?'));
        
        // Магия P2P: ищем тех, кто продает то, что мы хотим купить, 
        // и хочет купить то, что мы продаем. Фильтруем по объему (USD эквивалент).
        $sql = "SELECT o.*, u.first_name, u.username 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                WHERE o.user_id != ? 
                AND o.is_active = TRUE 
                AND o.status = 'open'
                AND o.sell_currency IN ($placeholders)
                AND JSON_CONTAINS(o.buy_currencies, ?)
                AND o.usd_amount >= ?
                ORDER BY o.usd_amount ASC
                LIMIT 10";
        
        $params = array_merge([$userId], $buyCurrenciesList, [json_encode($sellCurrency), $userUsdValue]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function createOrder($userId, $sellCurrency, $amount, $buyCurrencies) {
        $usdAmount = CurrencyRates::toUsd($amount, $sellCurrency);
        $stmt = $this->pdo->prepare("INSERT INTO orders (user_id, sell_currency, amount, usd_amount, buy_currencies) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $sellCurrency, $amount, $usdAmount, json_encode($buyCurrencies)]);
    }

    /**
     * Получение активных заявок пользователя
     */
    public function getUserOrders($userId) {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE user_id = ? AND is_active = TRUE ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Получение истории заявок
     */
    public function getHistory($userId) {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE user_id = ? AND is_active = FALSE ORDER BY updated_at DESC LIMIT 20");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Закрытие заявки
     */
    public function closeOrder($orderId, $userId) {
        $stmt = $this->pdo->prepare("UPDATE orders SET is_active = FALSE, status = 'closed' WHERE id = ? AND user_id = ?");
        return $stmt->execute([$orderId, $userId]);
    }

    public function updateAmount($orderId, $userId, $newAmount) {
        if ($newAmount <= 0) {
            return $this->closeOrder($orderId, $userId);
        }

        // Получаем валюту для пересчета USD объема
        $stmt = $this->pdo->prepare("SELECT sell_currency FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $usdAmount = CurrencyRates::toUsd($newAmount, $order['sell_currency']);

        $stmt = $this->pdo->prepare("UPDATE orders SET amount = ?, usd_amount = ?, status = 'partially_filled' WHERE id = ? AND user_id = ?");
        return $stmt->execute([$newAmount, $usdAmount, $orderId, $userId]);
    }

    /**
     * Поиск всех открытых заявок по конкретной валюте продажи
     */
    public function findByCurrency($userId, $sellCurrency) {
        $stmt = $this->pdo->prepare("SELECT o.*, u.first_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.user_id != ? AND o.sell_currency = ? AND o.is_active = TRUE AND o.status = 'open' LIMIT 25");
        $stmt->execute([$userId, $sellCurrency]);
        return $stmt->fetchAll();
    }
}
