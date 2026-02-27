<?php
/**
 * AlertService — Sistema de alertas personalizados por bairro
 * 
 * O usuário configura alertas tipo:
 * - "Me avise se chover no Cocó"
 * - "Alerta se UPA Messejana passar de 80%"
 * - "Notifique sobre segurança na Aldeota"
 * 
 * Armazena no banco por session/IP e verifica a cada ciclo SSE.
 * Usa Notification API no frontend (sem app nativo).
 */

class AlertService {
    private $db;

    // Tipos de alerta suportados
    const ALERT_TYPES = [
        'weather'  => ['label' => 'Clima (chuva, calor, vento)', 'icon' => '🌧'],
        'upa'      => ['label' => 'Lotação de UPA > limite',     'icon' => '🏥'],
        'security' => ['label' => 'Ocorrência de segurança',     'icon' => '🔒'],
        'transit'  => ['label' => 'Trânsito / interdição',       'icon' => '🚦'],
        'news'     => ['label' => 'Notícia sobre o bairro',      'icon' => '📰'],
        'defesa'   => ['label' => 'Alerta da Defesa Civil',      'icon' => '🚨'],
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->ensureTable();
    }

    /**
     * Criar tabela se não existir
     */
    private function ensureTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS city_alerts (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                session_id VARCHAR(64) NOT NULL,
                alert_type VARCHAR(30) NOT NULL,
                neighborhood VARCHAR(100) DEFAULT NULL,
                threshold_value FLOAT DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                last_triggered TIMESTAMP NULL DEFAULT NULL,
                trigger_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_active (is_active),
                INDEX idx_type (alert_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Registrar novo alerta para o usuário
     */
    public function createAlert($sessionId, $type, $neighborhood = null, $threshold = null) {
        if (!isset(self::ALERT_TYPES[$type])) {
            return ['success' => false, 'error' => 'Tipo de alerta inválido'];
        }

        // Limitar a 10 alertas por sessão
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM city_alerts WHERE session_id = ? AND is_active = 1");
        $stmt->execute([$sessionId]);
        if ($stmt->fetch()['cnt'] >= 10) {
            return ['success' => false, 'error' => 'Limite de 10 alertas atingido'];
        }

        // Verificar duplicata
        $stmt = $this->db->prepare("
            SELECT id FROM city_alerts 
            WHERE session_id = ? AND alert_type = ? AND (neighborhood = ? OR (neighborhood IS NULL AND ? IS NULL)) AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$sessionId, $type, $neighborhood, $neighborhood]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Alerta já existe'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO city_alerts (session_id, alert_type, neighborhood, threshold_value)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$sessionId, $type, $neighborhood, $threshold]);

        return [
            'success' => true,
            'data' => [
                'id'           => $this->db->lastInsertId(),
                'type'         => $type,
                'type_info'    => self::ALERT_TYPES[$type],
                'neighborhood' => $neighborhood,
                'threshold'    => $threshold,
            ],
        ];
    }

    /**
     * Listar alertas do usuário
     */
    public function getAlerts($sessionId) {
        $stmt = $this->db->prepare("
            SELECT id, alert_type, neighborhood, threshold_value, last_triggered, trigger_count, created_at
            FROM city_alerts WHERE session_id = ? AND is_active = 1 ORDER BY created_at DESC
        ");
        $stmt->execute([$sessionId]);
        $alerts = $stmt->fetchAll();

        foreach ($alerts as &$a) {
            $a['type_info'] = self::ALERT_TYPES[$a['alert_type']] ?? null;
        }

        return ['success' => true, 'data' => $alerts, 'types' => self::ALERT_TYPES];
    }

    /**
     * Desativar alerta
     */
    public function deleteAlert($sessionId, $alertId) {
        $stmt = $this->db->prepare("UPDATE city_alerts SET is_active = 0 WHERE id = ? AND session_id = ?");
        $stmt->execute([$alertId, $sessionId]);
        return ['success' => true];
    }

    /**
     * Verificar se algum alerta deve disparar
     * Chamado pelo SSE loop ou pelo realtime refresh
     */
    public function checkTriggers($sessionId, $currentData) {
        $stmt = $this->db->prepare("
            SELECT * FROM city_alerts WHERE session_id = ? AND is_active = 1
            AND (last_triggered IS NULL OR last_triggered < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
        ");
        $stmt->execute([$sessionId]);
        $alerts = $stmt->fetchAll();

        $triggered = [];

        foreach ($alerts as $alert) {
            $result = $this->evaluateAlert($alert, $currentData);
            if ($result) {
                $triggered[] = $result;

                // Marcar como disparado
                $stmt2 = $this->db->prepare("
                    UPDATE city_alerts SET last_triggered = NOW(), trigger_count = trigger_count + 1 WHERE id = ?
                ");
                $stmt2->execute([$alert['id']]);
            }
        }

        return $triggered;
    }

    /**
     * Avaliar se um alerta individual deve disparar
     */
    private function evaluateAlert($alert, $data) {
        $type = $alert['alert_type'];
        $neighborhood = mb_strtolower($alert['neighborhood'] ?? '');
        $threshold = $alert['threshold_value'];

        switch ($type) {
            case 'weather':
                $weather = $data['weather'] ?? null;
                if (!$weather) return null;
                $rain = $weather['current']['rain'] ?? 0;
                $wind = $weather['current']['wind_speed'] ?? 0;
                $temp = $weather['current']['temperature'] ?? 0;
                
                if ($rain > 5 || $wind > 40 || $temp > 36) {
                    return [
                        'alert_id'  => $alert['id'],
                        'type'      => 'weather',
                        'icon'      => '🌧',
                        'title'     => $rain > 5 ? "Chuva detectada: {$rain}mm" : ($temp > 36 ? "Calor extremo: {$temp}°C" : "Vento forte: {$wind}km/h"),
                        'message'   => $this->weatherAlertMessage($rain, $wind, $temp),
                        'severity'  => $rain > 20 ? 'alto' : 'moderado',
                    ];
                }
                break;

            case 'upa':
                $upas = $data['upas'] ?? [];
                $limit = $threshold ?: 80;
                foreach ($upas as $upa) {
                    $upaNeighborhood = mb_strtolower($upa['bairro'] ?? '');
                    if ($neighborhood && $upaNeighborhood !== $neighborhood) continue;
                    if (($upa['lotacao_pct'] ?? 0) >= $limit) {
                        return [
                            'alert_id'  => $alert['id'],
                            'type'      => 'upa',
                            'icon'      => '🏥',
                            'title'     => "{$upa['nome']} — {$upa['lotacao_pct']}% lotação",
                            'message'   => "A {$upa['nome']} atingiu {$upa['lotacao_pct']}% de lotação. Tempo de espera: ~{$upa['tempo_espera_estimado']}min.",
                            'severity'  => $upa['lotacao_pct'] >= 90 ? 'alto' : 'moderado',
                        ];
                    }
                }
                break;

            case 'security':
                $events = $data['security_events'] ?? [];
                foreach ($events as $ev) {
                    $evNeighborhood = mb_strtolower($ev['neighborhood'] ?? '');
                    if ($neighborhood && $evNeighborhood !== $neighborhood) continue;
                    return [
                        'alert_id'  => $alert['id'],
                        'type'      => 'security',
                        'icon'      => '🔒',
                        'title'     => $ev['title'] ?? 'Ocorrência de segurança',
                        'message'   => $ev['description'] ?? "Nova ocorrência no bairro {$ev['neighborhood']}.",
                        'severity'  => 'alto',
                    ];
                }
                break;

            case 'transit':
                $incidents = $data['transit_incidents'] ?? [];
                foreach ($incidents as $inc) {
                    $incNeighborhood = mb_strtolower($inc['neighborhood'] ?? '');
                    if ($neighborhood && $incNeighborhood !== $neighborhood) continue;
                    return [
                        'alert_id'  => $alert['id'],
                        'type'      => 'transit',
                        'icon'      => '🚦',
                        'title'     => $inc['title'] ?? 'Interdição / Trânsito',
                        'message'   => $inc['description'] ?? '',
                        'severity'  => 'moderado',
                    ];
                }
                break;

            case 'news':
                $news = $data['news'] ?? [];
                foreach ($news as $n) {
                    $newsNeighborhood = mb_strtolower($n['neighborhood'] ?? '');
                    if ($neighborhood && $newsNeighborhood !== $neighborhood) continue;
                    return [
                        'alert_id'  => $alert['id'],
                        'type'      => 'news',
                        'icon'      => '📰',
                        'title'     => $n['title'] ?? 'Nova notícia',
                        'message'   => mb_substr($n['description'] ?? $n['title'] ?? '', 0, 200),
                        'severity'  => 'ok',
                    ];
                }
                break;

            case 'defesa':
                $dcAlerts = $data['defesa_civil'] ?? [];
                if (!empty($dcAlerts)) {
                    $dc = $dcAlerts[0];
                    return [
                        'alert_id'  => $alert['id'],
                        'type'      => 'defesa',
                        'icon'      => '🚨',
                        'title'     => $dc['title'] ?? 'Alerta da Defesa Civil',
                        'message'   => $dc['description'] ?? '',
                        'severity'  => $dc['severity'] ?? 'alto',
                    ];
                }
                break;
        }

        return null;
    }

    private function weatherAlertMessage($rain, $wind, $temp) {
        $parts = [];
        if ($rain > 5) $parts[] = "Precipitação: {$rain}mm";
        if ($wind > 40) $parts[] = "Vento: {$wind}km/h";
        if ($temp > 36) $parts[] = "Temperatura: {$temp}°C";
        return implode(' | ', $parts) . '. Mantenha-se informado.';
    }
}
