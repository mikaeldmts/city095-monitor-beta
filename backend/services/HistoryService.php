<?php
/**
 * HistoryService — Histórico de tendências para gráficos temporais
 * 
 * Armazena snapshots periódicos de:
 * - Índice de segurança por bairro
 * - Lotação de UPAs
 * - Menções de bairros
 * - Categorias trending
 * - Clima (temperatura, chuva)
 * 
 * Permite gerar gráficos de evolução temporal:
 * "Como Messejana evoluiu em segurança nos últimos 7 dias?"
 */

class HistoryService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->ensureTable();
    }

    private function ensureTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS city_history (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                metric_type ENUM('safety', 'upa_occupancy', 'mentions', 'category_trend', 'weather', 'transit') NOT NULL,
                metric_key VARCHAR(100) NOT NULL,
                metric_value FLOAT NOT NULL,
                extra_json JSON DEFAULT NULL,
                snapshot_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type_key (metric_type, metric_key),
                INDEX idx_snapshot (snapshot_at DESC),
                INDEX idx_type_time (metric_type, snapshot_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Salvar snapshot de segurança por bairro
     */
    public function snapshotSafety($safetyData) {
        if (empty($safetyData)) return;

        // Evitar duplicatas (máx 1 snapshot a cada 60min por tipo)
        if ($this->hasRecentSnapshot('safety', 3600)) return;

        $stmt = $this->db->prepare("
            INSERT INTO city_history (metric_type, metric_key, metric_value, extra_json)
            VALUES ('safety', ?, ?, ?)
        ");

        foreach ($safetyData as $item) {
            $bairro = $item['bairro'] ?? $item['neighborhood'] ?? 'desconhecido';
            $index = $item['safety_index'] ?? $item['index'] ?? 0;
            $extra = json_encode([
                'lat' => $item['lat'] ?? null,
                'lng' => $item['lng'] ?? null,
                'crime_rate' => $item['crime_rate'] ?? null,
            ]);
            $stmt->execute([$bairro, $index, $extra]);
        }
    }

    /**
     * Salvar snapshot de lotação das UPAs
     */
    public function snapshotUPAs($upasData) {
        if (empty($upasData)) return;
        if ($this->hasRecentSnapshot('upa_occupancy', 3600)) return;

        $stmt = $this->db->prepare("
            INSERT INTO city_history (metric_type, metric_key, metric_value, extra_json)
            VALUES ('upa_occupancy', ?, ?, ?)
        ");

        foreach ($upasData as $upa) {
            $nome = $upa['nome'] ?? 'UPA desconhecida';
            $pct = $upa['lotacao_pct'] ?? 0;
            $extra = json_encode([
                'tempo_espera'     => $upa['tempo_espera_estimado'] ?? null,
                'medicos'          => $upa['medicos_servico'] ?? null,
                'bairro'           => $upa['bairro'] ?? null,
                'status'           => $upa['status'] ?? null,
            ]);
            $stmt->execute([$nome, $pct, $extra]);
        }
    }

    /**
     * Salvar snapshot de menções de bairros
     */
    public function snapshotMentions($topNeighborhoods) {
        if (empty($topNeighborhoods)) return;
        if ($this->hasRecentSnapshot('mentions', 3600)) return;

        $stmt = $this->db->prepare("
            INSERT INTO city_history (metric_type, metric_key, metric_value, extra_json)
            VALUES ('mentions', ?, ?, NULL)
        ");

        foreach ($topNeighborhoods as $nb) {
            $stmt->execute([$nb['neighborhood'] ?? '', $nb['count'] ?? 0]);
        }
    }

    /**
     * Salvar snapshot de categorias trending
     */
    public function snapshotCategories($categories) {
        if (empty($categories)) return;
        if ($this->hasRecentSnapshot('category_trend', 3600)) return;

        $stmt = $this->db->prepare("
            INSERT INTO city_history (metric_type, metric_key, metric_value, extra_json)
            VALUES ('category_trend', ?, ?, ?)
        ");

        foreach ($categories as $cat) {
            $extra = json_encode([
                'avg_sentiment' => $cat['avg_sentiment'] ?? null,
            ]);
            $stmt->execute([
                $cat['category'] ?? 'outros',
                $cat['count'] ?? $cat['mention_count'] ?? 0,
                $extra,
            ]);
        }
    }

    /**
     * Salvar snapshot do clima
     */
    public function snapshotWeather($weatherData) {
        if (empty($weatherData)) return;
        if ($this->hasRecentSnapshot('weather', 3600)) return;

        $current = $weatherData['current'] ?? $weatherData;
        $stmt = $this->db->prepare("
            INSERT INTO city_history (metric_type, metric_key, metric_value, extra_json)
            VALUES ('weather', 'fortaleza', ?, ?)
        ");

        $extra = json_encode([
            'feels_like'    => $current['feels_like'] ?? null,
            'humidity'      => $current['humidity'] ?? null,
            'rain'          => $current['rain'] ?? 0,
            'wind_speed'    => $current['wind_speed'] ?? null,
            'uv_index'      => $current['uv_index'] ?? null,
            'weather_desc'  => $current['weather_desc'] ?? null,
        ]);

        $stmt->execute([$current['temperature'] ?? 0, $extra]);
    }

    /**
     * Obter histórico de um bairro (para gráfico temporal)
     */
    public function getNeighborhoodHistory($bairro, $metricType = 'safety', $days = 7) {
        $stmt = $this->db->prepare("
            SELECT metric_value, extra_json, snapshot_at
            FROM city_history
            WHERE metric_type = ? AND metric_key = ?
            AND snapshot_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY snapshot_at ASC
        ");
        $stmt->execute([$metricType, mb_strtolower($bairro), $days]);
        $rows = $stmt->fetchAll();

        return array_map(function($r) {
            return [
                'value'   => (float) $r['metric_value'],
                'extra'   => json_decode($r['extra_json'], true),
                'time'    => $r['snapshot_at'],
            ];
        }, $rows);
    }

    /**
     * Obter histórico de UPA 
     */
    public function getUPAHistory($upaName, $days = 7) {
        return $this->getNeighborhoodHistory($upaName, 'upa_occupancy', $days);
    }

    /**
     * Obter histórico de clima
     */
    public function getWeatherHistory($days = 7) {
        return $this->getNeighborhoodHistory('fortaleza', 'weather', $days);
    }

    /**
     * Obter dashboard de histórico completo
     */
    public function getDashboardHistory($days = 7) {
        return [
            'safety' => $this->getTopMetricHistory('safety', $days, 10),
            'upa_occupancy' => $this->getTopMetricHistory('upa_occupancy', $days, 12),
            'mentions' => $this->getTopMetricHistory('mentions', $days, 10),
            'weather' => $this->getNeighborhoodHistory('fortaleza', 'weather', $days),
            'categories' => $this->getTopMetricHistory('category_trend', $days, 15),
            'period' => [
                'days'  => $days,
                'from'  => date('c', strtotime("-{$days} days")),
                'to'    => date('c'),
            ],
        ];
    }

    /**
     * Top N keys de um tipo de métrica ao longo do tempo
     */
    private function getTopMetricHistory($type, $days, $limit) {
        // Pegar os top keys por média de valor
        $stmt = $this->db->prepare("
            SELECT metric_key, AVG(metric_value) as avg_val, COUNT(*) as data_points
            FROM city_history
            WHERE metric_type = ? AND snapshot_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY metric_key
            ORDER BY avg_val DESC
            LIMIT ?
        ");
        $stmt->execute([$type, $days, $limit]);
        $topKeys = $stmt->fetchAll();

        $result = [];
        foreach ($topKeys as $k) {
            $history = $this->getNeighborhoodHistory($k['metric_key'], $type, $days);
            $result[] = [
                'key'         => $k['metric_key'],
                'avg_value'   => round((float) $k['avg_val'], 3),
                'data_points' => (int) $k['data_points'],
                'history'     => $history,
            ];
        }

        return $result;
    }

    /**
     * Salvar todos os snapshots de uma vez (chamado pelo cron/realtime)
     */
    public function saveAllSnapshots($data) {
        try {
            if (!empty($data['safety'])) $this->snapshotSafety($data['safety']);
            if (!empty($data['upas'])) $this->snapshotUPAs($data['upas']);
            if (!empty($data['neighborhoods'])) $this->snapshotMentions($data['neighborhoods']);
            if (!empty($data['categories'])) $this->snapshotCategories($data['categories']);
            if (!empty($data['weather'])) $this->snapshotWeather($data['weather']);
            return true;
        } catch (Exception $e) {
            error_log("HistoryService snapshot error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verificar se já tem snapshot recente para evitar duplicatas
     */
    private function hasRecentSnapshot($type, $seconds = 3600) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM city_history
                WHERE metric_type = ? AND snapshot_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                LIMIT 1
            ");
            $stmt->execute([$type, $seconds]);
            return ($stmt->fetch()['cnt'] ?? 0) > 0;
        } catch (Exception $e) { return false; }
    }

    /**
     * Cleanup — remover dados com mais de 90 dias
     */
    public function cleanup($days = 90) {
        $stmt = $this->db->prepare("DELETE FROM city_history WHERE snapshot_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
