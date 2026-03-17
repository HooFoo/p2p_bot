<?php

class OrderManager {
    private $pdo;
    private $config;

    public function __construct($pdo, $config) {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * Поиск подходящих заявок для пользователя
     */
    public function findMatches($userId, $sellCurrency, $buyCurrencies) {
        $buyCurrenciesList = is_array($buyCurrencies) ? $buyCurrencies : json_decode($buyCurrencies, true);
        
        if (empty($buyCurrenciesList)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($buyCurrenciesList), '?'));
        
        // Магия P2P: ищем тех, кто продает то, что мы хотим купить, 
        // и хочет купить то, что мы продаем.
        $sql = "SELECT o.*, u.first_name, u.username 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                WHERE o.user_id != ? 
                AND o.is_active = TRUE 
                AND o.status = 'open'
                AND o.sell_currency IN ($placeholders)
                AND JSON_CONTAINS(o.buy_currencies, ?)
                LIMIT 10";
        
        $params = array_merge([$userId], $buyCurrenciesList, [json_encode($sellCurrency)]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Создание новой заявки
     */
    public function createOrder($userId, $sellCurrency, $amount, $buyCurrencies) {
        $stmt = $this->pdo->prepare("INSERT INTO orders (user_id, sell_currency, amount, buy_currencies) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $sellCurrency, $amount, json_encode($buyCurrencies)]);
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

    /**
     * Изменение объема заявки
     */
    public function updateAmount($orderId, $userId, $newAmount) {
        if ($newAmount <= 0) {
            return $this->closeOrder($orderId, $userId);
        }
        $stmt = $this->pdo->prepare("UPDATE orders SET amount = ?, status = 'partially_filled' WHERE id = ? AND user_id = ?");
        return $stmt->execute([$newAmount, $orderId, $userId]);
    }
}
