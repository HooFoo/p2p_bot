<?php

class CurrencyRates {
    /**
     * Курсы валют относительно USD (USD = 1)
     * Данные для примера, в реальности можно обновлять из API
     */
    private static $rates = [
        'USD'  => 1.0,
        'USDT' => 1.0,
        'RUB'  => 82.16,
        'GEL'  => 2.71,
    ];

    /**
     * Конвертация суммы из одной валюты в другую через USD
     */
    public static function convert($amount, $from, $to) {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if (!isset(self::$rates[$from]) || !isset(self::$rates[$to])) {
            return $amount; // Если валюты нет в словаре, возвращаем как есть (или 0)
        }

        if ($from === $to) return $amount;

        // Переводим в USD, затем в целевую валюту
        $inUsd = $amount / self::$rates[$from];
        return $inUsd * self::$rates[$to];
    }

    /**
     * Получить эквивалент в USD
     */
    public static function toUsd($amount, $currency) {
        return self::convert($amount, $currency, 'USD');
    }

    /**
     * Получить все доступные курсы
     */
    public static function getRates() {
        return self::$rates;
    }
}
