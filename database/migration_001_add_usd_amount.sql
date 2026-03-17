-- Миграция: Добавление колонки объема в USD для фильтрации заявок
ALTER TABLE `orders` 
ADD COLUMN `usd_amount` DECIMAL(15,2) DEFAULT 0.00 AFTER `amount`;

-- (Опционально) Индексируем для быстрого поиска по объему
CREATE INDEX idx_orders_usd_amount ON `orders` (`usd_amount`);
CREATE INDEX idx_orders_matching ON `orders` (`is_active`, `status`, `sell_currency`);
