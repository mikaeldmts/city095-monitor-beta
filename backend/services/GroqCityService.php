<?php
/**
 * GroqCityService — Serviço de IA para o Monitor Urbano
 * Usa a API Groq (LLaMA) para:
 * - Chat sobre assuntos da cidade
 * - Extração de tópicos e palavras-chave
 * - Classificação por categoria e sentimento
 * - Geolocalização aproximada de assuntos mencionados
 */

class GroqCityService {
    private $apiKeys = [];
    private $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';
    private $model   = 'llama-3.3-70b-versatile';
    private $currentKeyIndex = 0;

    // Cache simples em memória para evitar chamadas duplicadas
    private static $topicCache = [];

    // Bairros de Fortaleza para geolocalização
    private $neighborhoods = [
        'aldeota' => [-3.7340, -38.5080],
        'meireles' => [-3.7240, -38.5030],
        'centro' => [-3.7230, -38.5280],
        'benfica' => [-3.7410, -38.5380],
        'fatima' => [-3.7450, -38.5280],
        'montese' => [-3.7590, -38.5350],
        'messejana' => [-3.8310, -38.4920],
        'papicu' => [-3.7360, -38.4930],
        'cocó' => [-3.7460, -38.4820],
        'dionísio torres' => [-3.7430, -38.5060],
        'parquelândia' => [-3.7370, -38.5510],
        'jacarecanga' => [-3.7200, -38.5420],
        'praia de iracema' => [-3.7210, -38.5140],
        'mucuripe' => [-3.7220, -38.4890],
        'varjota' => [-3.7310, -38.4960],
        'edson queiroz' => [-3.7710, -38.4780],
        'cambeba' => [-3.8020, -38.4850],
        'parangaba' => [-3.7750, -38.5530],
        'maraponga' => [-3.7960, -38.5650],
        'mondubim' => [-3.7940, -38.5780],
        'barra do ceará' => [-3.6960, -38.5540],
        'carlito pamplona' => [-3.7100, -38.5460],
        'pirambu' => [-3.7100, -38.5400],
        'antonio bezerra' => [-3.7380, -38.5680],
        'henrique jorge' => [-3.7530, -38.5670],
        'jóquei clube' => [-3.7530, -38.5550],
        'presidente kennedy' => [-3.7460, -38.5450],
        'cidade dos funcionários' => [-3.7870, -38.4970],
        'passaré' => [-3.8020, -38.5230],
        'cajazeiras' => [-3.8110, -38.5110],
        'lagoa redonda' => [-3.8260, -38.4650],
        'josé walter' => [-3.8210, -38.5470],
        'conjunto ceará' => [-3.7830, -38.5970],
        'bom jardim' => [-3.7850, -38.5890],
        'granja portugal' => [-3.7780, -38.5770],
        'vila velha' => [-3.7000, -38.5500],
        'são gerardo' => [-3.7290, -38.5510],
        'farias brito' => [-3.7380, -38.5350],
        'amadeu furtado' => [-3.7390, -38.5440],
        'rodolfo teófilo' => [-3.7430, -38.5530],
        'pici' => [-3.7470, -38.5720],
        'aeroporto' => [-3.7760, -38.5310],
        'serrinha' => [-3.7700, -38.5340],
        'itaperi' => [-3.7900, -38.5460],
        'dendê' => [-3.7540, -38.5280],
        'pan americano' => [-3.7510, -38.5350],
        'damas' => [-3.7500, -38.5410],
        'bom futuro' => [-3.7460, -38.5310],
        'joaquim távora' => [-3.7400, -38.5170],
        'são joão do tauape' => [-3.7510, -38.5100],
        'salinas' => [-3.7860, -38.4540],
        'sapiranga' => [-3.7930, -38.4640],
        'couto fernandes' => [-3.7540, -38.5440],
        'gentilândia' => [-3.7370, -38.5440],
        'jardim américa' => [-3.7650, -38.5130],
        'água fria' => [-3.7780, -38.5480],
        'vila união' => [-3.7580, -38.5070],
        'parque manibura' => [-3.7930, -38.4810],
        'luciano cavalcante' => [-3.7660, -38.4860],
        'guararapes' => [-3.7600, -38.4780],
        'dunas' => [-3.7530, -38.4680],
        'de lourdes' => [-3.7520, -38.4770],
        'praia do futuro' => [-3.7480, -38.4550],
        'vicente pinzón' => [-3.7400, -38.4690],
        'cais do porto' => [-3.7330, -38.4750],
    ];

    public function __construct() {
        // Carregar todas as chaves disponíveis
        $key1 = getenv('GROQ_API_KEY');
        $key2 = getenv('GROQ_API_KEY_2');

        if ($key1) $this->apiKeys[] = $key1;
        if ($key2) $this->apiKeys[] = $key2;

        // Alternar chave inicial baseado no segundo atual (distribuir carga)
        if (count($this->apiKeys) > 1) {
            $this->currentKeyIndex = time() % count($this->apiKeys);
        }
    }

    public function isAvailable() {
        return !empty($this->apiKeys);
    }

    /**
     * Obter a chave atual
     */
    private function getCurrentKey() {
        return $this->apiKeys[$this->currentKeyIndex] ?? null;
    }

    /**
     * Rotacionar para a próxima chave
     */
    private function rotateKey() {
        if (count($this->apiKeys) <= 1) return false;
        $this->currentKeyIndex = ($this->currentKeyIndex + 1) % count($this->apiKeys);
        return true;
    }

    /**
     * Enviar request para a API Groq com rotação de chaves e retry automático
     */
    private function request($messages, $temperature = 0.7, $maxTokens = 2000, $jsonMode = false) {
        if (!$this->isAvailable()) return null;

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $maxRetries = count($this->apiKeys); // Tentar cada chave uma vez
        $lastError = null;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $apiKey = $this->getCurrentKey();
            if (!$apiKey) break;

            $ch = curl_init($this->baseUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_TIMEOUT => 60,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                error_log("Groq City API cURL error: $error");
                $lastError = $error;
                $this->rotateKey();
                continue;
            }

            // Rate limit atingido — rotacionar chave e tentar de novo
            if ($httpCode === 429) {
                $keyNum = $this->currentKeyIndex + 1;
                error_log("Groq API rate limited (key $keyNum), rotating to next key...");
                $lastError = "Rate limited on key $keyNum";
                if (!$this->rotateKey()) {
                    // Sem mais chaves, checar retry-after
                    $data = json_decode($response, true);
                    $retryMsg = $data['error']['message'] ?? 'Rate limit reached on all keys';
                    error_log("Groq API: all keys exhausted — $retryMsg");
                    return null;
                }
                continue;
            }

            if ($httpCode !== 200) {
                error_log("Groq City API error ($httpCode): $response");
                return null;
            }

            $data = json_decode($response, true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            return $content;
        }

        error_log("Groq City API: all retries exhausted — $lastError");
        return null;
    }

    /**
     * Chat sobre a cidade - responde como um assistente urbano de Fortaleza
     * AGORA recebe contexto real coletado das APIs antes de responder
     *
     * @param string $userMessage — mensagem do usuário
     * @param array  $history     — histórico de conversa
     * @param string $realContext — dados reais coletados (clima, UPAs, notícias, segurança)
     */
    public function cityChat($userMessage, $history = [], $realContext = '') {
        $systemPrompt = <<<PROMPT
Você é o CityBot 085, assistente do MONITOR URBANO DE FORTALEZA.

REGRAS:
- SEMPRE use os dados reais fornecidos abaixo. Cite números específicos.
- NUNCA invente dados. Se não tem dado, diga que não tem.
- Seja direto (máx 3 parágrafos). Use emojis moderadamente.
- Cite fontes: Open-Meteo, CKAN/Prefeitura, SSPDS/CE.
- UPAs: cite lotação, médicos, tempo. Se >70%, sugira alternativas.
- Responda em português brasileiro.
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        // Injetar contexto real como mensagem do sistema
        if (!empty($realContext)) {
            $messages[] = [
                'role'    => 'system',
                'content' => "DADOS EM TEMPO REAL (coletados agora das APIs):\n\n" . $realContext,
            ];
        }

        // Adicionar histórico (até 6 mensagens para economizar tokens)
        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = [
                    'role'    => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $this->request($messages, 0.6, 1200, false);
    }

    /**
     * Extrair tópicos, categorias e sentimento de uma mensagem
     * Com cache para evitar chamadas duplicadas
     */
    public function extractTopics($message) {
        // Cache por hash da mensagem (evitar repetições)
        $cacheKey = md5($message);
        if (isset(self::$topicCache[$cacheKey])) {
            return self::$topicCache[$cacheKey];
        }

        $neighborhoodList = implode(', ', array_keys($this->neighborhoods));

        $systemPrompt = <<<PROMPT
Analise a mensagem sobre Fortaleza/CE e retorne JSON:
{"topics":["t1","t2"],"category":"cat","sentiment":"positivo|negativo|neutro","neighborhoods":["bairro"],"relevance":0.0-1.0,"is_event":false,"event_title":null,"summary":"resumo curto"}

Categorias: seguranca, transito, saude, educacao, eventos, politica, clima, infraestrutura, cultura, economia, meio_ambiente, esporte, tecnologia, servicos_publicos, outros
Bairros: {$neighborhoodList}
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ];

        $content = $this->request($messages, 0.1, 400, true);

        if (!$content) return null;

        $result = json_decode($content, true);
        if (!$result) {
            $result = json_decode($content, true);
        }

        // Adicionar coordenadas dos bairros
        if (isset($result['neighborhoods']) && is_array($result['neighborhoods'])) {
            $result['coordinates'] = [];
            foreach ($result['neighborhoods'] as $neighborhood) {
                $key = mb_strtolower(trim($neighborhood));
                if (isset($this->neighborhoods[$key])) {
                    $result['coordinates'][] = [
                        'neighborhood' => $neighborhood,
                        'lat' => $this->neighborhoods[$key][0],
                        'lng' => $this->neighborhoods[$key][1],
                    ];
                }
            }
        }

        // Salvar no cache
        self::$topicCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Gerar resumo dos trending topics para o dashboard
     */
    public function generateTrendingSummary($topics) {
        if (empty($topics)) return null;

        $topicsText = '';
        foreach ($topics as $t) {
            $topicsText .= "- {$t['topic']} ({$t['category']}, {$t['mention_count']}x menções)\n";
        }

        $systemPrompt = <<<PROMPT
Resuma em 2-3 frases os assuntos em alta em Fortaleza/CE. Seja direto. Português brasileiro.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Tópicos:\n$topicsText"],
        ];

        return $this->request($messages, 0.5, 300, false);
    }

    /**
     * Obter coordenadas de um bairro
     */
    public function getNeighborhoodCoords($neighborhood) {
        $key = mb_strtolower(trim($neighborhood));
        return $this->neighborhoods[$key] ?? null;
    }

    /**
     * Listar todos os bairros conhecidos
     */
    public function getNeighborhoods() {
        return $this->neighborhoods;
    }
}
