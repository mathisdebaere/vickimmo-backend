<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../../vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

class AuctionServer implements MessageComponentInterface
{
    protected $clients;
    protected $auctions;      // auctionId => SplObjectStorage
    protected $clientAuctions; // connectionId => auctionId
    protected $db;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->auctions = [];
        $this->clientAuctions = [];
        $this->db = $this->connectDatabase();
        
        echo "Auction WebSocket server started...\n";
    }

    private function connectDatabase()
    {
        try {
            $host = getenv('DB_HOST') ?: 'mariadb';
            $dbname = getenv('DB_NAME') ?: 'immo';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: 'root';
            
            $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            return $pdo;
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection: " . spl_object_id($conn) . "\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['action'])) {
            $this->sendError($from, 'Invalid message format');
            return;
        }

        switch ($data['action']) {
            case 'join':
                $this->handleJoin($from, $data);
                break;
            case 'bid':
                $this->handleBid($from, $data);
                break;
            case 'leave':
                $this->handleLeave($from);
                break;
            default:
                $this->sendError($from, 'Unknown action');
        }
    }

    private function handleJoin(ConnectionInterface $conn, array $data)
    {
        if (!isset($data['auction_id'])) {
            $this->sendError($conn, 'auction_id required');
            return;
        }

        $auctionId = (int)$data['auction_id'];
        
        // Verify auction exists and is active
        $auction = $this->getAuction($auctionId);
        if (!$auction) {
            $this->sendError($conn, 'Auction not found or ended');
            return;
        }

        // Create auction room if it doesn't exist
        if (!isset($this->auctions[$auctionId])) {
            $this->auctions[$auctionId] = new \SplObjectStorage();
        }

        // Add client to auction room
        $this->auctions[$auctionId]->attach($conn);
        $this->clientAuctions[spl_object_id($conn)] = $auctionId;

        // Get current highest bid
        $highestBid = $this->getHighestBid($auctionId);

        // Send confirmation to client
        $conn->send(json_encode([
            'type' => 'joined',
            'auction_id' => $auctionId,
            'auction' => $auction,
            'highest_bid' => $highestBid,
            'participants' => count($this->auctions[$auctionId])
        ]));

        // Notify other participants
        $this->broadcastToAuction($auctionId, [
            'type' => 'participant_joined',
            'participants' => count($this->auctions[$auctionId])
        ], $conn);

        echo "Client " . spl_object_id($conn) . 
             " joined auction: $auctionId\n";
    }

    private function handleBid(ConnectionInterface $conn, array $data)
    {
        $connId = spl_object_id($conn);
        $auctionId = $this->clientAuctions[$connId] ?? null;

        if (!$auctionId) {
            $this->sendError($conn, 'Not joined to any auction');
            return;
        }

        if (!isset($data['user_id'], $data['bid_amount'])) {
            $this->sendError($conn, 'user_id and bid_amount required');
            return;
        }

        $userId = (int)$data['user_id'];
        $bidAmount = (float)$data['bid_amount'];

        // Validate bid
        $validation = $this->validateBid($auctionId, $bidAmount);
        if (!$validation['valid']) {
            $this->sendError($conn, $validation['error']);
            return;
        }

        // Save bid to database
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO bids (user_id, auction_id, bid_amount) 
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([$userId, $auctionId, $bidAmount]);
            $bidId = $this->db->lastInsertId();

            // Broadcast new bid to all auction participants
            $this->broadcastToAuction($auctionId, [
                'type' => 'new_bid',
                'bid' => [
                    'id' => $bidId,
                    'user_id' => $userId,
                    'auction_id' => $auctionId,
                    'bid_amount' => $bidAmount,
                    'created_at' => date('Y-m-d H:i:s')
                ],
                'highest_bid' => $bidAmount
            ]);

            echo "New bid: $bidAmount on auction $auctionId by user $userId\n";

        } catch (PDOException $e) {
            $this->sendError($conn, 'Failed to save bid');
            echo "Bid error: " . $e->getMessage() . "\n";
        }
    }

    private function handleLeave(ConnectionInterface $conn)
    {
        $connId = spl_object_id($conn);
        $auctionId = $this->clientAuctions[$connId] ?? null;

        if ($auctionId && isset($this->auctions[$auctionId])) {
            $this->auctions[$auctionId]->detach($conn);
            
            $participants = count($this->auctions[$auctionId]);
            $this->broadcastToAuction($auctionId, [
                'type' => 'participant_left',
                'participants' => $participants
            ]);
        }

        unset($this->clientAuctions[$connId]);
        echo "Client $connId left auction\n";
    }

    private function getAuction(int $auctionId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM auctions 
                 WHERE id = ? AND ending_time > NOW()"
            );
            $stmt->execute([$auctionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ?: null;
        } catch (PDOException $e) {
            echo "Error fetching auction: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function getHighestBid(int $auctionId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM bids 
                 WHERE auction_id = ? 
                 ORDER BY bid_amount DESC 
                 LIMIT 1"
            );
            $stmt->execute([$auctionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ?: null;
        } catch (PDOException $e) {
            echo "Error fetching highest bid: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function validateBid(int $auctionId, float $bidAmount): array
    {
        $auction = $this->getAuction($auctionId);
        
        if (!$auction) {
            return ['valid' => false, 'error' => 'Auction ended or not found'];
        }

        $highestBid = $this->getHighestBid($auctionId);
        $minBid = $highestBid 
            ? $highestBid['bid_amount'] + ($auction['overbidding'] ?? 100)
            : $auction['starting_price'];

        if ($bidAmount < $minBid) {
            return [
                'valid' => false, 
                'error' => "Bid must be at least $minBid"
            ];
        }

        return ['valid' => true];
    }

    private function broadcastToAuction(
        int $auctionId, 
        array $message, 
        ?ConnectionInterface $except = null
    ) {
        if (!isset($this->auctions[$auctionId])) return;

        $json = json_encode($message);
        foreach ($this->auctions[$auctionId] as $client) {
            if ($except && $client === $except) continue;
            $client->send($json);
        }
    }

    private function sendError(ConnectionInterface $conn, string $message)
    {
        $conn->send(json_encode([
            'type' => 'error',
            'message' => $message
        ]));
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->handleLeave($conn);
        $this->clients->detach($conn);
        echo "Connection " . spl_object_id($conn) . " closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: " . $e->getMessage() . "\n";
        $conn->close();
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new AuctionServer()
        )
    ),
    80
);

$server->run();