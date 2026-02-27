<?php
/**
 * CityChatController — Chat público sobre assuntos de Fortaleza
 * AGORA consulta APIs reais antes de responder (clima, UPAs, notícias, segurança)
 */

require_once __DIR__ . '/../services/GroqCityService.php';
require_once __DIR__ . '/../services/WeatherService.php';
require_once __DIR__ . '/../services/UPAService.php';
require_once __DIR__ . '/../services/NewsService.php';
require_once __DIR__ . '/../services/GamificationService.php';

$localPath = realpath(__DIR__ . '/../../../backend');
$serverPath = realpath(__DIR__ . '/../../../api');
$portfolioRoot = $localPath ?: $serverPath;
require_once $portfolioRoot . '/config/database.php';

class CityChatController {
    private $db;
    private $groq;
    private $weather;
    private $upa;
    private $news;
    private $gamification;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->groq = new GroqCityService();
        $this->weather = new WeatherService();
        $this->upa = new UPAService();
        $this->news = new NewsService();
        $this->gamification = new GamificationService();
    }

    /**
     * POST /api/chat — Receber mensagem e retornar resposta IA com DADOS REAIS
     */
    public function chat() {
        if (!$this->groq->isAvailable()) {
            return [
                'success' => false,
                'error'   => 'Serviço de IA não configurado',
            ];
        }

        // Ler body JSON
        $input = json_decode(file_get_contents('php://input'), true);
        $userMessage = trim($input['message'] ?? '');
        $history     = $input['history'] ?? [];
        $sessionId   = $input['session_id'] ?? $this->generateSessionId();

        if (empty($userMessage)) {
            return ['success' => false, 'error' => 'Mensagem é obrigatória'];
        }

        if (mb_strlen($userMessage) > 2000) {
            return ['success' => false, 'error' => 'Mensagem muito longa (máx 2000 caracteres)'];
        }

        // Rate limiting básico por IP (máx 20 msgs/hora — economia de tokens)
        if ($this->isRateLimited()) {
            return ['success' => false, 'error' => 'Muitas mensagens. Tente novamente em alguns minutos.'];
        }

        // 1. Salvar mensagem do usuário
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . date('Y-m-d'));
        $userMsgId = $this->saveMessage($sessionId, 'user', $userMessage, $ipHash);

        // 2. Extrair tópicos — com fallback local se Groq falhar (economiza tokens)
        $topics = $this->groq->extractTopics($userMessage);

        // Fallback: extração básica por regex se a IA não respondeu
        if (!$topics) {
            $topics = $this->localExtractTopics($userMessage);
        }

        // 3. Salvar tópicos extraídos
        if ($topics && !empty($topics['topics'])) {
            $this->saveTopics($userMsgId, $topics);
        }

        // 4. ★ COLETAR DADOS REAIS das APIs baseado no que o usuário perguntou ★
        $realContext = $this->collectRealTimeContext($userMessage, $topics);

        // 5. Gerar resposta do chat COM contexto real
        $aiResponse = $this->groq->cityChat($userMessage, $history, $realContext);

        if ($aiResponse === null) {
            // Fallback: se temos dados reais, montar resposta básica sem IA
            if (!empty($realContext)) {
                $aiResponse = "⚠️ **Serviço de IA temporariamente indisponível** (rate limit atingido).\n\n"
                    . "Mas aqui estão os dados reais que coletei para você:\n\n"
                    . $realContext;
            } else {
                return [
                    'success' => false,
                    'error'   => 'Serviço de IA temporariamente indisponível. Tente novamente em alguns minutos.',
                ];
            }
        }

        // 6. Salvar resposta da IA
        $this->saveMessage($sessionId, 'assistant', $aiResponse, null);

        // 6.5. Gamificação — premiar participação
        $gamificationResult = null;
        try {
            $gamificationResult = $this->gamification->recordActivity($sessionId, [
                'topics'      => $topics['topics'] ?? [],
                'category'    => $topics['category'] ?? 'outros',
                'neighborhood' => $topics['neighborhood'] ?? null,
                'relevance'   => $topics['relevance'] ?? 0.5,
                'sentiment'   => $topics['sentiment'] ?? 'neutro',
            ]);
        } catch (Exception $e) {
            error_log("Gamification error: " . $e->getMessage());
        }

        // 7. Criar evento se detectado
        if ($topics && !empty($topics['is_event']) && !empty($topics['event_title'])) {
            $this->createEvent($topics);
        }

        return [
            'success' => true,
            'data'    => [
                'message'    => $aiResponse,
                'session_id' => $sessionId,
                'topics'     => $topics['topics'] ?? [],
                'category'   => $topics['category'] ?? 'outros',
                'sentiment'  => $topics['sentiment'] ?? 'neutro',
                'sources'    => $this->getUsedSources($topics),
                'gamification' => $gamificationResult,
            ],
        ];
    }

    /**
     * ★ CORE — Coletar dados em tempo real para injetar no prompt da IA
     * Decide quais APIs consultar com base na pergunta do usuário
     */
    private function collectRealTimeContext($message, $topics) {
        $context = [];
        $msgLower = mb_strtolower($message);
        $category = $topics['category'] ?? 'outros';
        $neighborhoods = $topics['neighborhoods'] ?? [];
        $bairro = $neighborhoods[0] ?? null;

        // === SEMPRE: Clima atual (rápido, ~200ms) ===
        try {
            $weather = $this->weather->getCurrentWeather();
            if ($weather && isset($weather['current'])) {
                $cur = $weather['current'];
                $context[] = "=== CLIMA ATUAL (Open-Meteo, agora) ===";
                $context[] = "Temperatura: {$cur['temperature']}°C (sensação: {$cur['feels_like']}°C)";
                $context[] = "Condição: {$cur['weather_desc']} {$cur['weather_icon']}";
                $context[] = "Umidade: {$cur['humidity']}% | Vento: {$cur['wind_speed']}km/h | UV: {$cur['uv_index']}";

                if (!empty($weather['alerts'])) {
                    $context[] = "⚠️ ALERTAS CLIMÁTICOS:";
                    foreach ($weather['alerts'] as $a) {
                        $context[] = "  - {$a['icon']} {$a['title']}: {$a['desc']}";
                    }
                }
                $context[] = "";
            }
        } catch (Exception $e) {
            error_log("Chat context weather error: " . $e->getMessage());
        }

        // === UPAs: quando menção a saúde, UPA, hospital, médico, ou qualquer bairro ===
        $needsUPA = (
            $category === 'saude' ||
            preg_match('/\b(upa|hospital|médico|medico|saúde|saude|emergência|emergencia|pronto.?atendimento|lotação|lotada|leito|espera|doente|febre|dor|acidente)\b/i', $msgLower) ||
            !empty($bairro) // Se menciona um bairro, mostrar a UPA mais próxima
        );

        if ($needsUPA) {
            try {
                $upaData = $this->upa->getUPASummaryForChat($bairro);
                $context[] = $upaData;
                $context[] = "";
            } catch (Exception $e) {
                error_log("Chat context UPA error: " . $e->getMessage());
            }
        }

        // === NOTÍCIAS: sempre que mencionar bairro, segurança, trânsito, ou pedir "como está" ===
        $needsNews = (
            !empty($bairro) ||
            $category === 'seguranca' ||
            $category === 'transito' ||
            preg_match('/\b(como est[aá]|notícia|noticias|acontecendo|ocorrência|ocorrencia|situação|situacao|estado|perigoso|violência|roubo|assalto|acidente|blitz|operação)\b/i', $msgLower)
        );

        if ($needsNews) {
            try {
                $news = $this->news->getNews($bairro, 8);
                if (!empty($news)) {
                    $context[] = "=== NOTÍCIAS RECENTES" . ($bairro ? " — " . mb_strtoupper($bairro) : " — FORTALEZA") . " (Google News RSS) ===";
                    foreach ($news as $i => $n) {
                        $nbInfo = $n['neighborhood'] ? " [📍{$n['neighborhood']}]" : '';
                        $context[] = ($i+1) . ". {$n['title']}{$nbInfo}";
                        if (!empty($n['description'])) {
                            $context[] = "   {$n['description']}";
                        }
                        $context[] = "   Fonte: {$n['source']} | {$n['pubDate']}";
                    }
                    $context[] = "";
                }
            } catch (Exception $e) {
                error_log("Chat context news error: " . $e->getMessage());
            }
        }

        // === SEGURANÇA: índice de segurança do bairro ===
        $needsSafety = (
            !empty($bairro) ||
            $category === 'seguranca' ||
            preg_match('/\b(segur|perig|violen|crime|roubo|assalto|morte|tiro|facção|policia)\b/i', $msgLower)
        );

        if ($needsSafety) {
            try {
                $safety = $this->news->getNeighborhoodSafetyIndex();
                if (!empty($safety)) {
                    $context[] = "=== ÍNDICE DE SEGURANÇA POR BAIRRO (dados SSPDS/CE + notícias recentes) ===";
                    
                    if ($bairro) {
                        // Mostrar bairro pedido + vizinhos
                        $bairroLower = mb_strtolower($bairro);
                        foreach ($safety as $s) {
                            if (mb_strtolower($s['neighborhood']) === $bairroLower) {
                                $context[] = "📍 {$s['neighborhood']}: Índice {$s['safety_index']} ({$s['level']}) | Menções crimes recentes: {$s['recent_crimes']}";
                                break;
                            }
                        }
                        // Top 3 mais seguros e 3 menos seguros para comparação
                        $safest = array_slice(array_reverse($safety), 0, 3);
                        $dangerous = array_slice($safety, 0, 3);
                        $context[] = "Comparação — Mais seguros: " . implode(', ', array_map(fn($s) => "{$s['neighborhood']}({$s['safety_index']})", $safest));
                        $context[] = "Comparação — Atenção: " . implode(', ', array_map(fn($s) => "{$s['neighborhood']}({$s['safety_index']})", $dangerous));
                    } else {
                        // Resumo geral
                        $dangerous = array_slice($safety, 0, 5);
                        $safest = array_slice(array_reverse($safety), 0, 5);
                        $context[] = "Bairros mais seguros: " . implode(', ', array_map(fn($s) => "{$s['neighborhood']}({$s['safety_index']})", $safest));
                        $context[] = "Bairros com mais atenção: " . implode(', ', array_map(fn($s) => "{$s['neighborhood']}({$s['safety_index']})", $dangerous));
                    }
                    $context[] = "Escala: 0.0=alto risco, 1.0=muito seguro";
                    $context[] = "";
                }
            } catch (Exception $e) {
                error_log("Chat context safety error: " . $e->getMessage());
            }
        }

        // === EVENTOS RECENTES do banco (trending) ===
        try {
            $stmt = $this->db->prepare("
                SELECT title, category, neighborhood, mention_count, created_at
                FROM city_events
                WHERE is_active = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                " . ($bairro ? "AND LOWER(neighborhood) = ?" : "") . "
                ORDER BY mention_count DESC, created_at DESC
                LIMIT 10
            ");
            $bairro ? $stmt->execute([mb_strtolower($bairro)]) : $stmt->execute();
            $events = $stmt->fetchAll();

            if (!empty($events)) {
                $context[] = "=== EVENTOS/OCORRÊNCIAS RECENTES (últimas 24h)" . ($bairro ? " — " . mb_strtoupper($bairro) : "") . " ===";
                foreach ($events as $ev) {
                    $nb = $ev['neighborhood'] ? " [{$ev['neighborhood']}]" : '';
                    $context[] = "- {$ev['title']}{$nb} ({$ev['category']}, {$ev['mention_count']}x menções)";
                }
                $context[] = "";
            }
        } catch (Exception $e) {
            error_log("Chat context events error: " . $e->getMessage());
        }

        // === QUALIDADE DO AR ===
        if ($category === 'clima' || $category === 'meio_ambiente' || preg_match('/\b(ar|poluição|qualidade|respirat)\b/i', $msgLower)) {
            try {
                $air = $this->weather->getAirQuality();
                if ($air) {
                    $context[] = "=== QUALIDADE DO AR (Open-Meteo) ===";
                    $context[] = "AQI: {$air['aqi']} ({$air['aqi_label']}) | PM2.5: {$air['pm2_5']}µg/m³ | PM10: {$air['pm10']}µg/m³";
                    $context[] = "";
                }
            } catch (Exception $e) {}
        }

        return implode("\n", $context);
    }

    /**
     * Fontes utilizadas para a resposta
     */
    private function getUsedSources($topics) {
        $sources = ['Groq AI (LLaMA)'];
        $sources[] = 'Open-Meteo (clima)';

        $category = $topics['category'] ?? 'outros';
        $neighborhoods = $topics['neighborhoods'] ?? [];

        if ($category === 'saude' || !empty($neighborhoods)) {
            $sources[] = 'UPAs Fortaleza (CKAN/estimativa)';
        }
        if (!empty($neighborhoods) || $category === 'seguranca') {
            $sources[] = 'Google News RSS';
            $sources[] = 'SSPDS/CE (segurança)';
        }
        return $sources;
    }

    /**
     * Salvar mensagem no banco
     */
    private function saveMessage($sessionId, $role, $content, $ipHash = null) {
        $stmt = $this->db->prepare(
            "INSERT INTO city_messages (session_id, role, content, ip_hash) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$sessionId, $role, $content, $ipHash]);
        return $this->db->lastInsertId();
    }

    /**
     * Salvar tópicos extraídos
     */
    private function saveTopics($messageId, $topicData) {
        $category  = $topicData['category'] ?? 'outros';
        $sentiment = $topicData['sentiment'] ?? 'neutro';
        $relevance = $topicData['relevance'] ?? 0.5;

        foreach ($topicData['topics'] as $topic) {
            $neighborhood = null;
            $lat = null;
            $lng = null;

            // Usar primeira coordenada encontrada
            if (!empty($topicData['coordinates'])) {
                $coord = $topicData['coordinates'][0];
                $neighborhood = $coord['neighborhood'];
                $lat = $coord['lat'];
                $lng = $coord['lng'];
            } elseif (!empty($topicData['neighborhoods'])) {
                $neighborhood = $topicData['neighborhoods'][0];
            }

            $stmt = $this->db->prepare(
                "INSERT INTO city_topics (message_id, topic, category, sentiment, relevance, neighborhood, latitude, longitude)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $messageId,
                mb_substr($topic, 0, 255),
                $category,
                $sentiment,
                $relevance,
                $neighborhood,
                $lat,
                $lng,
            ]);
        }
    }

    /**
     * Criar evento a partir de tópicos detectados
     */
    private function createEvent($topicData) {
        $title = $topicData['event_title'] ?? $topicData['summary'] ?? '';
        if (empty($title)) return;

        $lat = null;
        $lng = null;
        $neighborhood = null;

        if (!empty($topicData['coordinates'])) {
            $coord = $topicData['coordinates'][0];
            $neighborhood = $coord['neighborhood'];
            $lat = $coord['lat'];
            $lng = $coord['lng'];
        }

        // Verificar se evento similar já existe (últimas 24h)
        $stmt = $this->db->prepare(
            "SELECT id, mention_count FROM city_events
             WHERE title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 1"
        );
        $stmt->execute([$title]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Incrementar menções
            $stmt = $this->db->prepare(
                "UPDATE city_events SET mention_count = mention_count + 1 WHERE id = ?"
            );
            $stmt->execute([$existing['id']]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO city_events (title, description, category, source, neighborhood, latitude, longitude)
                 VALUES (?, ?, ?, 'chat', ?, ?, ?)"
            );
            $stmt->execute([
                $title,
                $topicData['summary'] ?? '',
                $topicData['category'] ?? 'outros',
                $neighborhood,
                $lat,
                $lng,
            ]);
        }
    }

    /**
     * Rate limiting por IP
     */
    private function isRateLimited() {
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . date('Y-m-d'));

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM city_messages
             WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$ipHash]);
        $result = $stmt->fetch();

        return ($result['cnt'] ?? 0) >= 20;
    }

    /**
     * Gerar session ID anônimo
     */
    private function generateSessionId() {
        return 'city_' . bin2hex(random_bytes(16));
    }

    /**
     * Fallback local para extração de tópicos quando a Groq API não responde
     * Usa regex para detectar categoria, bairros e sentimento sem gastar tokens
     */
    private function localExtractTopics($message) {
        $msgLower = mb_strtolower($message);

        // Detectar categoria por keywords
        $categoryMap = [
            'seguranca' => '/\b(segur|perig|violen|crime|roubo|assalto|morte|tiro|facção|policia|preso|matar|arma)\b/i',
            'transito'  => '/\b(trânsito|transito|acidente|blitz|engarrafamento|congestion|br\s*\d|avenida|semáforo|via|pista)\b/i',
            'saude'     => '/\b(upa|hospital|médico|medico|saúde|saude|emergência|emergencia|doente|febre|dor|vacina|posto)\b/i',
            'clima'     => '/\b(clima|chuva|sol|temp|calor|frio|vento|previsão|tempo|nublado|temporal)\b/i',
            'eventos'   => '/\b(evento|show|festa|jogo|fortal|carnaval|concert|agenda)\b/i',
            'educacao'  => '/\b(escola|universidade|ufc|uece|ifce|colégio|aula|vestibular|enem)\b/i',
            'transporte' => '/\b(ônibus|onibus|metrô|metro|uber|corrida|passagem|rota|linha)\b/i',
        ];

        $category = 'outros';
        foreach ($categoryMap as $cat => $pattern) {
            if (preg_match($pattern, $msgLower)) {
                $category = $cat;
                break;
            }
        }

        // Detectar bairros mencionados
        $neighborhoods = [];
        $bairrosConhecidos = $this->groq->getNeighborhoods();
        foreach (array_keys($bairrosConhecidos) as $bairro) {
            if (mb_stripos($msgLower, $bairro) !== false) {
                $neighborhoods[] = $bairro;
            }
        }

        // Sentimento básico
        $sentiment = 'neutro';
        if (preg_match('/\b(bom|ótimo|otimo|legal|tranquil|segur|melhor|top|excelente)\b/i', $msgLower)) {
            $sentiment = 'positivo';
        } elseif (preg_match('/\b(ruim|péssim|pessim|perig|horrível|horrivel|pior|caótic|caotico|terrível|terrivel|medo)\b/i', $msgLower)) {
            $sentiment = 'negativo';
        }

        // Extrair palavras-chave como tópicos
        $words = preg_split('/\s+/', $msgLower);
        $stopwords = ['o','a','os','as','de','da','do','em','na','no','que','e','para','com','um','uma','por','como','está','esta','tem','ser','foi','isso','esse','essa','qual','quem','algum','agora','hoje','aqui'];
        $topics = array_values(array_filter($words, fn($w) => mb_strlen($w) > 3 && !in_array($w, $stopwords)));
        $topics = array_slice($topics, 0, 3);

        return [
            'topics'        => $topics ?: ['consulta geral'],
            'category'      => $category,
            'sentiment'     => $sentiment,
            'neighborhoods' => $neighborhoods,
            'relevance'     => 0.5,
            'is_event'      => false,
            'event_title'   => null,
            'summary'       => mb_substr($message, 0, 100),
            'fallback'      => true, // Flag para saber que veio do fallback
        ];
    }
}
