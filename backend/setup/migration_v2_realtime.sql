-- =============================================
-- City085 Monitor — Migration v2: Real-Time Data
-- Execute no phpMyAdmin ou terminal
-- =============================================

-- Adicionar novos tipos de source no city_events
ALTER TABLE city_events 
  MODIFY COLUMN source ENUM('chat', 'open_data', 'manual', 'weather_api', 'air_quality_api', 'holidays_api', 'seed', 'auto') DEFAULT 'chat';

-- Adicionar coluna description se não existir (safety check)
-- ALTER TABLE city_events ADD COLUMN IF NOT EXISTS description TEXT AFTER title;
