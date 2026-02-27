<?php
/**
 * GamificationService — Ranking de contribuidores do City085
 * 
 * Sistema de pontos baseado em:
 * - Mensagens no chat que geram tópicos úteis
 * - Relatos de eventos (segurança, trânsito, etc.)
 * - Menções de bairros com dados relevantes
 * - Alertas confirmados
 * 
 * Cada participação dá pontos. Top contribuidores do mês ganham destaque.
 * Badges por bairro para quem mais contribui sobre determinado local.
 */

class GamificationService {
    private $db;

    // Pontos por ação
    const POINTS = [
        'chat_message'     => 1,
        'topic_extracted'  => 3,
        'event_reported'   => 5,
        'neighborhood_ref' => 2,
        'alert_confirmed'  => 4,
        'high_relevance'   => 3,   // tópico com relevance > 0.7
        'negative_report'  => 2,   // denúncia/reclamação
    ];

    // Badges disponíveis
    const BADGES = [
        'reporter'      => ['label' => 'Repórter',       'icon' => '📰', 'desc' => 'Enviou +10 relatos'],
        'guardian'       => ['label' => 'Guardião',       'icon' => '🛡', 'desc' => 'Reportou +5 problemas de segurança'],
        'eyes_on'       => ['label' => 'De Olho',        'icon' => '👁', 'desc' => 'Mencionou +10 bairros diferentes'],
        'first_report'  => ['label' => 'Primeiro Alerta','icon' => '🏁', 'desc' => 'Primeira mensagem no monitor'],
        'top_month'     => ['label' => 'Destaque do Mês','icon' => '🏆', 'desc' => 'Top contribuidor do mês'],
        'weather_watch' => ['label' => 'Vigia do Clima', 'icon' => '🌦', 'desc' => 'Reportou +3 eventos climáticos'],
        'transit_hero'  => ['label' => 'Herói do Trânsito','icon' => '🚦', 'desc' => 'Reportou +5 ocorrências de trânsito'],
        'health_helper' => ['label' => 'Ajuda Saúde',    'icon' => '🏥', 'desc' => 'Informou sobre UPAs/saúde +3 vezes'],
        'streaker'      => ['label' => 'Sequência',      'icon' => '🔥', 'desc' => 'Contribuiu 7 dias seguidos'],
        'neighborhood_expert' => ['label' => 'Expert Local', 'icon' => '📍', 'desc' => 'Top contribuidor de um bairro'],
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->ensureTables();
    }

    private function ensureTables() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS city_gamification (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                session_id VARCHAR(64) NOT NULL,
                display_name VARCHAR(50) DEFAULT NULL,
                total_points INT DEFAULT 0,
                monthly_points INT DEFAULT 0,
                total_messages INT DEFAULT 0,
                total_topics INT DEFAULT 0,
                total_events INT DEFAULT 0,
                neighborhoods_mentioned JSON DEFAULT NULL,
                categories_contributed JSON DEFAULT NULL,
                badges JSON DEFAULT NULL,
                streak_days INT DEFAULT 0,
                last_active DATE DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_points (total_points DESC),
                INDEX idx_monthly (monthly_points DESC),
                UNIQUE KEY uk_session (session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS city_point_log (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                session_id VARCHAR(64) NOT NULL,
                action VARCHAR(30) NOT NULL,
                points INT NOT NULL,
                detail VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_action (action),
                INDEX idx_created (created_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Registrar atividade do usuário e calcular pontos
     */
    public function recordActivity($sessionId, $activity) {
        $pointsEarned = 0;
        $actions = [];

        // 1. Mensagem enviada
        $pointsEarned += self::POINTS['chat_message'];
        $actions[] = 'chat_message';

        // 2. Tópicos extraídos
        $topics = $activity['topics'] ?? [];
        if (!empty($topics)) {
            $pts = count($topics) * self::POINTS['topic_extracted'];
            $pointsEarned += $pts;
            $actions[] = 'topic_extracted';
        }

        // 3. Bairro mencionado
        $neighborhood = $activity['neighborhood'] ?? null;
        if ($neighborhood) {
            $pointsEarned += self::POINTS['neighborhood_ref'];
            $actions[] = 'neighborhood_ref';
        }

        // 4. Evento reportado (tópico com categoria de evento)
        $category = $activity['category'] ?? '';
        $eventCategories = ['seguranca', 'transito', 'saude', 'infraestrutura', 'clima'];
        if (in_array($category, $eventCategories)) {
            $pointsEarned += self::POINTS['event_reported'];
            $actions[] = 'event_reported';
        }

        // 5. Alta relevância
        $relevance = $activity['relevance'] ?? 0;
        if ($relevance > 0.7) {
            $pointsEarned += self::POINTS['high_relevance'];
            $actions[] = 'high_relevance';
        }

        // 6. Sentimento negativo (denúncia/reclamação = mais valor)
        $sentiment = $activity['sentiment'] ?? 'neutro';
        if ($sentiment === 'negativo') {
            $pointsEarned += self::POINTS['negative_report'];
            $actions[] = 'negative_report';
        }

        // Atualizar perfil
        $this->updateProfile($sessionId, $pointsEarned, $actions, $neighborhood, $category, $topics);

        // Registrar log de pontos
        $this->logPoints($sessionId, $actions, $pointsEarned);

        // Checar badges
        $newBadges = $this->checkBadges($sessionId);

        return [
            'points_earned' => $pointsEarned,
            'actions'       => $actions,
            'new_badges'    => $newBadges,
        ];
    }

    /**
     * Atualizar perfil do contribuidor
     */
    private function updateProfile($sessionId, $points, $actions, $neighborhood, $category, $topics) {
        $today = date('Y-m-d');

        // Buscar perfil existente
        $stmt = $this->db->prepare("SELECT * FROM city_gamification WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            // Criar novo perfil
            $neighborhoods = $neighborhood ? [$neighborhood => 1] : [];
            $categories = $category ? [$category => 1] : [];

            $stmt = $this->db->prepare("
                INSERT INTO city_gamification 
                (session_id, total_points, monthly_points, total_messages, total_topics, neighborhoods_mentioned, categories_contributed, badges, streak_days, last_active)
                VALUES (?, ?, ?, 1, ?, ?, ?, '[]', 1, ?)
            ");
            $stmt->execute([
                $sessionId, $points, $points, count($topics),
                json_encode($neighborhoods), json_encode($categories), $today,
            ]);
            return;
        }

        // Atualizar existente
        $neighborhoods = json_decode($profile['neighborhoods_mentioned'] ?? '{}', true) ?: [];
        if ($neighborhood) {
            $neighborhoods[$neighborhood] = ($neighborhoods[$neighborhood] ?? 0) + 1;
        }

        $categories = json_decode($profile['categories_contributed'] ?? '{}', true) ?: [];
        if ($category) {
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }

        // Streak
        $streakDays = $profile['streak_days'] ?? 0;
        $lastActive = $profile['last_active'] ?? '';
        if ($lastActive === date('Y-m-d', strtotime('-1 day'))) {
            $streakDays++;
        } elseif ($lastActive !== $today) {
            $streakDays = 1;
        }

        // Reset monthly points se novo mês
        $monthlyPoints = $profile['monthly_points'] ?? 0;
        if (date('Y-m', strtotime($profile['updated_at'] ?? 'now')) !== date('Y-m')) {
            $monthlyPoints = 0;
        }

        $stmt = $this->db->prepare("
            UPDATE city_gamification SET
                total_points = total_points + ?,
                monthly_points = ? + ?,
                total_messages = total_messages + 1,
                total_topics = total_topics + ?,
                neighborhoods_mentioned = ?,
                categories_contributed = ?,
                streak_days = ?,
                last_active = ?
            WHERE session_id = ?
        ");
        $stmt->execute([
            $points, $monthlyPoints, $points, count($topics),
            json_encode($neighborhoods), json_encode($categories),
            $streakDays, $today, $sessionId,
        ]);
    }

    /**
     * Checar e conceder badges
     */
    private function checkBadges($sessionId) {
        $stmt = $this->db->prepare("SELECT * FROM city_gamification WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $profile = $stmt->fetch();
        if (!$profile) return [];

        $currentBadges = json_decode($profile['badges'] ?? '[]', true) ?: [];
        $newBadges = [];

        $neighborhoods = json_decode($profile['neighborhoods_mentioned'] ?? '{}', true) ?: [];
        $categories = json_decode($profile['categories_contributed'] ?? '{}', true) ?: [];

        // Primeiro relato
        if (!in_array('first_report', $currentBadges) && $profile['total_messages'] >= 1) {
            $newBadges[] = 'first_report';
        }

        // Repórter — +10 mensagens
        if (!in_array('reporter', $currentBadges) && $profile['total_messages'] >= 10) {
            $newBadges[] = 'reporter';
        }

        // Guardião — 5+ segurança
        if (!in_array('guardian', $currentBadges) && ($categories['seguranca'] ?? 0) >= 5) {
            $newBadges[] = 'guardian';
        }

        // De olho — 10+ bairros mencionados
        if (!in_array('eyes_on', $currentBadges) && count($neighborhoods) >= 10) {
            $newBadges[] = 'eyes_on';
        }

        // Vigia do clima
        if (!in_array('weather_watch', $currentBadges) && ($categories['clima'] ?? 0) >= 3) {
            $newBadges[] = 'weather_watch';
        }

        // Herói do trânsito
        if (!in_array('transit_hero', $currentBadges) && ($categories['transito'] ?? 0) >= 5) {
            $newBadges[] = 'transit_hero';
        }

        // Ajuda Saúde
        if (!in_array('health_helper', $currentBadges) && ($categories['saude'] ?? 0) >= 3) {
            $newBadges[] = 'health_helper';
        }

        // Sequência — 7 dias seguidos
        if (!in_array('streaker', $currentBadges) && ($profile['streak_days'] ?? 0) >= 7) {
            $newBadges[] = 'streaker';
        }

        // Expert Local — 10+ menções de um bairro
        if (!in_array('neighborhood_expert', $currentBadges)) {
            foreach ($neighborhoods as $nb => $count) {
                if ($count >= 10) {
                    $newBadges[] = 'neighborhood_expert';
                    break;
                }
            }
        }

        // Salvar novos badges
        if (!empty($newBadges)) {
            $allBadges = array_unique(array_merge($currentBadges, $newBadges));
            $stmt = $this->db->prepare("UPDATE city_gamification SET badges = ? WHERE session_id = ?");
            $stmt->execute([json_encode($allBadges), $sessionId]);
        }

        return array_map(function($b) {
            return array_merge(self::BADGES[$b] ?? [], ['id' => $b]);
        }, $newBadges);
    }

    /**
     * Registrar pontos no log
     */
    private function logPoints($sessionId, $actions, $totalPoints) {
        $stmt = $this->db->prepare("
            INSERT INTO city_point_log (session_id, action, points, detail)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$sessionId, implode(',', $actions), $totalPoints, null]);
    }

    /**
     * Ranking mensal (top 20)
     */
    public function getMonthlyRanking($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT session_id, display_name, monthly_points, total_points, total_messages,
                   total_topics, total_events, badges, streak_days, 
                   neighborhoods_mentioned, categories_contributed, last_active
            FROM city_gamification
            WHERE monthly_points > 0
            ORDER BY monthly_points DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll();

        return array_map(function($r, $i) {
            return [
                'rank'              => $i + 1,
                'session_id'        => $r['session_id'],
                'display_name'      => $r['display_name'] ?: $this->generateAnonymousName($r['session_id']),
                'monthly_points'    => (int) $r['monthly_points'],
                'total_points'      => (int) $r['total_points'],
                'total_messages'    => (int) $r['total_messages'],
                'badges'            => $this->expandBadges(json_decode($r['badges'] ?? '[]', true)),
                'streak_days'       => (int) $r['streak_days'],
                'top_neighborhoods' => $this->getTopFromJson($r['neighborhoods_mentioned'], 3),
                'top_categories'    => $this->getTopFromJson($r['categories_contributed'], 3),
                'last_active'       => $r['last_active'],
            ];
        }, $rows, array_keys($rows));
    }

    /**
     * Ranking geral (all-time top 20)
     */
    public function getAllTimeRanking($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT session_id, display_name, total_points, total_messages, badges, streak_days,
                   neighborhoods_mentioned, categories_contributed
            FROM city_gamification
            WHERE total_points > 0
            ORDER BY total_points DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll();

        return array_map(function($r, $i) {
            return [
                'rank'           => $i + 1,
                'display_name'   => $r['display_name'] ?: $this->generateAnonymousName($r['session_id']),
                'total_points'   => (int) $r['total_points'],
                'total_messages' => (int) $r['total_messages'],
                'badges'         => $this->expandBadges(json_decode($r['badges'] ?? '[]', true)),
            ];
        }, $rows, array_keys($rows));
    }

    /**
     * Perfil do contribuidor
     */
    public function getProfile($sessionId) {
        $stmt = $this->db->prepare("SELECT * FROM city_gamification WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            return [
                'exists'        => false,
                'total_points'  => 0,
                'badges'        => [],
                'available_badges' => self::BADGES,
            ];
        }

        return [
            'exists'           => true,
            'display_name'     => $profile['display_name'] ?: $this->generateAnonymousName($sessionId),
            'total_points'     => (int) $profile['total_points'],
            'monthly_points'   => (int) $profile['monthly_points'],
            'total_messages'   => (int) $profile['total_messages'],
            'total_topics'     => (int) $profile['total_topics'],
            'streak_days'      => (int) $profile['streak_days'],
            'badges'           => $this->expandBadges(json_decode($profile['badges'] ?? '[]', true)),
            'neighborhoods'    => json_decode($profile['neighborhoods_mentioned'] ?? '{}', true),
            'categories'       => json_decode($profile['categories_contributed'] ?? '{}', true),
            'created_at'       => $profile['created_at'],
            'last_active'      => $profile['last_active'],
            'available_badges' => self::BADGES,
        ];
    }

    /**
     * Definir nome de exibição
     */
    public function setDisplayName($sessionId, $name) {
        $name = mb_substr(strip_tags(trim($name)), 0, 50);
        if (empty($name)) return ['success' => false, 'error' => 'Nome inválido'];

        // Verificar se já existe outro com mesmo nome
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM city_gamification WHERE display_name = ? AND session_id != ?");
        $stmt->execute([$name, $sessionId]);
        if ($stmt->fetch()['cnt'] > 0) {
            return ['success' => false, 'error' => 'Nome já está em uso'];
        }

        $stmt = $this->db->prepare("UPDATE city_gamification SET display_name = ? WHERE session_id = ?");
        $stmt->execute([$name, $sessionId]);
        return ['success' => true, 'display_name' => $name];
    }

    /**
     * Top contribuidores de um bairro
     */
    public function getNeighborhoodTopContributors($neighborhood, $limit = 5) {
        $stmt = $this->db->prepare("
            SELECT session_id, display_name, total_points, badges, neighborhoods_mentioned
            FROM city_gamification
            WHERE JSON_EXTRACT(neighborhoods_mentioned, ?) IS NOT NULL
            ORDER BY total_points DESC
            LIMIT ?
        ");
        $key = '$."' . addslashes($neighborhood) . '"';
        $stmt->execute([$key, $limit]);
        $rows = $stmt->fetchAll();

        return array_map(function($r) use ($neighborhood) {
            $nbs = json_decode($r['neighborhoods_mentioned'] ?? '{}', true);
            return [
                'display_name' => $r['display_name'] ?: $this->generateAnonymousName($r['session_id']),
                'total_points' => (int) $r['total_points'],
                'neighborhood_mentions' => $nbs[$neighborhood] ?? 0,
                'badges' => $this->expandBadges(json_decode($r['badges'] ?? '[]', true)),
            ];
        }, $rows);
    }

    /**
     * Gerar nome anônimo baseado no session ID
     */
    private function generateAnonymousName($sessionId) {
        $adjectives = ['Veloz', 'Atento', 'Bravo', 'Esperto', 'Ágil', 'Forte', 'Firme', 'Sagaz'];
        $nouns = ['Cidadão', 'Vigia', 'Guardião', 'Morador', 'Repórter', 'Voluntário', 'Observador', 'Fiscal'];
        $hash = crc32($sessionId);
        $adj = $adjectives[abs($hash) % count($adjectives)];
        $noun = $nouns[abs($hash >> 8) % count($nouns)];
        $num = abs($hash % 999) + 1;
        return "{$adj} {$noun} #{$num}";
    }

    /**
     * Expandir IDs de badges para objetos completos
     */
    private function expandBadges($badgeIds) {
        if (!is_array($badgeIds)) return [];
        return array_map(function($id) {
            return array_merge(self::BADGES[$id] ?? ['label' => $id, 'icon' => '🏅'], ['id' => $id]);
        }, $badgeIds);
    }

    /**
     * Top N de um JSON column
     */
    private function getTopFromJson($json, $n) {
        $data = json_decode($json ?? '{}', true) ?: [];
        arsort($data);
        return array_slice($data, 0, $n, true);
    }
}
