<?php

namespace Http;

use \Services\ApiResponse;

class AuctionController extends BaseController
{
    private const VALID_AUCTION_STATUSES = ['upcoming', 'active', 'ended'];

    // AUCTION MANAGEMENT

    public function overview(): void
    {
        // Optimized: Use LEFT JOIN instead of subqueries
        $auctions = $this->db->fetchAllAssociative(
            'SELECT a.*, 
                    p.title as property_title, 
                    p.address, 
                    p.city,
                    COALESCE(MAX(b.bid_amount), a.starting_price) as current_price,
                    COUNT(DISTINCT b.id) as bid_count
             FROM auctions a
             JOIN properties p ON a.property_id = p.id
             LEFT JOIN bids b ON b.auction_id = a.id
             GROUP BY a.id
             ORDER BY a.starting_time DESC'
        );

        $formattedAuctions = array_map(function($auction) {
            return $this->formatAuctionResponse($auction);
        }, $auctions);

        ApiResponse::success($formattedAuctions);
    }

    public function post(): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $errors = $this->validateAuctionData($data, true);
        
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        if (!$this->propertyExists((int)$data['property_id'])) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        if ($this->propertyHasActiveAuction((int)$data['property_id'])) {
            ApiResponse::error('Property already has an active or upcoming auction.', 409);
            return;
        }

        $auctionData = $this->prepareAuctionData($data);

        $result = $this->db->executeStatement(
            'INSERT INTO auctions 
            (property_id, starting_price, starting_time, ending_time, overbidding) 
            VALUES (?, ?, ?, ?, ?)',
            array_values($auctionData)
        );

        if ($result === 1) {
            $id = $this->getLastInsertId();
            
            $this->db->executeStatement(
                'UPDATE properties SET status = "for auction" WHERE id = ?',
                [$data['property_id']]
            );
            
            $message = 'Auction created successfully.' . ($id ? " ID: $id" : '');
            ApiResponse::success(['message' => $message, 'id' => $id], status: 201);
        } else {
            ApiResponse::error('Could not create auction.', 500);
        }
    }

    public function getByID(int $auctionID): void
    {
        $auction = $this->db->fetchAssociative(
            'SELECT a.*, p.title as property_title, p.address, p.city, p.postal,
                    (SELECT MAX(b.bid_amount) FROM bids b WHERE b.auction_id = a.id) as current_price,
                    (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) as bid_count
             FROM auctions a
             JOIN properties p ON a.property_id = p.id
             WHERE a.id = ?',
            [$auctionID]
        );
        
        if ($auction === false) {
            ApiResponse::error('Auction not found.', 404);
            return;
        }
        
        ApiResponse::success($this->formatAuctionResponse($auction));
    }

    public function putByID(int $auctionID): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        if (!$this->auctionExists($auctionID)) {
            ApiResponse::error('Auction not found.', 404);
            return;
        }

        $errors = $this->validateAuctionData($data, false);
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        $updateData = $this->prepareUpdateData($data);

        if (empty($updateData)) {
            ApiResponse::error('No valid fields provided for update.', 422);
            return;
        }

        $fields = array_map(fn($field) => "$field = ?", array_keys($updateData));
        $params = array_merge(array_values($updateData), [$auctionID]);
        
        $sql = 'UPDATE auctions SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $result = $this->db->executeStatement($sql, $params);

        if ($result >= 0) {
            ApiResponse::success(['message' => 'Auction updated successfully.'], status: 200);
        } else {
            ApiResponse::error('Could not update auction.', 500);
        }
    }

    public function deleteByID(int $auctionID): void
    {
        $auction = $this->db->fetchAssociative(
            'SELECT property_id FROM auctions WHERE id = ?',
            [$auctionID]
        );

        if (!$auction) {
            ApiResponse::error('Auction not found.', 404);
            return;
        }

        try {
            $this->db->beginTransaction();
            
            $this->db->executeStatement(
                'DELETE FROM auctions WHERE id = ?',
                [$auctionID]
            );
            
            $this->db->executeStatement(
                'UPDATE properties SET status = "sold" WHERE id = ?',
                [$auction['property_id']]
            );
            
            $this->db->commit();
            
            ApiResponse::success(['message' => 'Auction and all associated bids deleted successfully.'], status: 200);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            ApiResponse::error('Failed to delete auction: ' . $e->getMessage(), 500);
        }
    }

    // BID MANAGEMENT

    public function getAllBids(int $auctionID): void
    {
        if (!$this->auctionExists($auctionID)) {
            ApiResponse::error('Auction not found.', 404);
            return;
        }

        $bids = $this->db->fetchAllAssociative(
            'SELECT b.id, b.bid_amount, b.created_at, 
                    u.id as user_id, u.name as user_name
             FROM bids b
             JOIN users u ON b.user_id = u.id
             WHERE b.auction_id = ?
             ORDER BY b.bid_amount DESC, b.created_at ASC',
            [$auctionID]
        );

        ApiResponse::success([
            'auction_id' => $auctionID,
            'bids' => array_map(fn($bid) => [
                'id' => (int)$bid['id'],
                'bid_amount' => (float)$bid['bid_amount'],
                'user_id' => (int)$bid['user_id'],
                'user_name' => $bid['user_name'],
                'created_at' => $bid['created_at']
            ], $bids)
        ]);
    }

    public function postBid(int $auctionID): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $auction = $this->db->fetchAssociative(
            'SELECT starting_price, starting_time, ending_time, overbidding 
             FROM auctions WHERE id = ?',
            [$auctionID]
        );

        if (!$auction) {
            ApiResponse::error('Auction not found.', 404);
            return;
        }

        $errors = $this->validateBidData($data);
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        $now = new \DateTime();
        $startTime = new \DateTime($auction['starting_time']);
        $endTime = new \DateTime($auction['ending_time']);

        if ($now < $startTime) {
            ApiResponse::error('Auction has not started yet.', 422);
            return;
        }

        if ($now > $endTime) {
            ApiResponse::error('Auction has ended.', 422);
            return;
        }

        $currentHighestBid = $this->getHighestBid($auctionID);
        $bidAmount = (float)$data['bid_amount'];
        $minimumBid = $currentHighestBid ? $currentHighestBid + ($auction['overbidding'] ?? 0) : (float)$auction['starting_price'];

        if ($bidAmount < $minimumBid) {
            ApiResponse::error("Bid must be at least €" . number_format($minimumBid, 2), 422);
            return;
        }

        $result = $this->db->executeStatement(
            'INSERT INTO bids (user_id, auction_id, bid_amount) VALUES (?, ?, ?)',
            [(int)$data['user_id'], $auctionID, $bidAmount]
        );

        if ($result === 1) {
            $id = $this->getLastInsertId();
            ApiResponse::success([
                'message' => 'Bid placed successfully.',
                'id' => $id,
                'bid_amount' => $bidAmount
            ], status: 201);
        } else {
            ApiResponse::error('Could not place bid.', 500);
        }
    }

    public function deleteBid(int $auctionID, int $bidID): void
    {
        $bid = $this->db->fetchAssociative(
            'SELECT id FROM bids WHERE id = ? AND auction_id = ?',
            [$bidID, $auctionID]
        );

        if (!$bid) {
            ApiResponse::error('Bid not found for this auction.', 404);
            return;
        }

        $this->db->executeStatement(
            'DELETE FROM bids WHERE id = ?',
            [$bidID]
        );

        ApiResponse::success(['message' => 'Bid deleted successfully.'], status: 200);
    }

    public function deleteAllBids(int $auctionID): void
    {
        if (!$this->auctionExists($auctionID)) {
            ApiResponse::error('Auction not found.', 404);
            return;
        }

        $deleted = $this->db->executeStatement(
            'DELETE FROM bids WHERE auction_id = ?',
            [$auctionID]
        );

        ApiResponse::success([
            'message' => "Deleted $deleted bid(s).",
            'auction_id' => $auctionID
        ]);
    }

    // AUCTION STATUS QUERIES

    public function getActiveAuctions(): void
    {
        $now = date('Y-m-d H:i:s');
        
        $auctions = $this->db->fetchAllAssociative(
            'SELECT a.*, p.title as property_title, p.address, p.city,
                    (SELECT MAX(b.bid_amount) FROM bids b WHERE b.auction_id = a.id) as current_price,
                    (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) as bid_count
             FROM auctions a
             JOIN properties p ON a.property_id = p.id
             WHERE a.starting_time <= ? AND a.ending_time > ?
             ORDER BY a.ending_time ASC',
            [$now, $now]
        );

        $formattedAuctions = array_map(fn($a) => $this->formatAuctionResponse($a), $auctions);
        
        ApiResponse::success($formattedAuctions);
    }

    public function getUpcomingAuctions(): void
    {
        $now = date('Y-m-d H:i:s');
        
        $auctions = $this->db->fetchAllAssociative(
            'SELECT a.*, p.title as property_title, p.address, p.city,
                    (SELECT MAX(b.bid_amount) FROM bids b WHERE b.auction_id = a.id) as current_price,
                    (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) as bid_count
             FROM auctions a
             JOIN properties p ON a.property_id = p.id
             WHERE a.starting_time > ?
             ORDER BY a.starting_time ASC',
            [$now]
        );

        $formattedAuctions = array_map(fn($a) => $this->formatAuctionResponse($a), $auctions);
        
        ApiResponse::success($formattedAuctions);
    }

    public function getEndedAuctions(): void
    {
        $now = date('Y-m-d H:i:s');
        
        $auctions = $this->db->fetchAllAssociative(
            'SELECT a.*, p.title as property_title, p.address, p.city,
                    (SELECT MAX(b.bid_amount) FROM bids b WHERE b.auction_id = a.id) as current_price,
                    (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) as bid_count
             FROM auctions a
             JOIN properties p ON a.property_id = p.id
             WHERE a.ending_time <= ?
             ORDER BY a.ending_time DESC',
            [$now]
        );

        $formattedAuctions = array_map(fn($a) => $this->formatAuctionResponse($a), $auctions);
        
        ApiResponse::success($formattedAuctions);
    }

    public function getAuctionsByProperty(int $propertyID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $auctions = $this->db->fetchAllAssociative(
            'SELECT a.*,
                    (SELECT MAX(b.bid_amount) FROM bids b WHERE b.auction_id = a.id) as current_price,
                    (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) as bid_count
             FROM auctions a
             WHERE a.property_id = ?
             ORDER BY a.starting_time DESC',
            [$propertyID]
        );

        $formattedAuctions = array_map(fn($a) => $this->formatAuctionResponse($a), $auctions);

        ApiResponse::success([
            'property_id' => $propertyID,
            'auctions' => $formattedAuctions
        ]);
    }

    // PRIVATE HELPER METHODS

    private function validateAuctionData(array $data, bool $isCreate): array
    {
        $errors = [];

        if ($isCreate) {
            if (!isset($data['property_id']) || (int)$data['property_id'] <= 0) {
                $errors[] = 'Valid property_id is required.';
            }

            if (!isset($data['starting_price']) || (float)$data['starting_price'] <= 0) {
                $errors[] = 'Starting price must be greater than 0.';
            }

            if (!isset($data['starting_time']) || empty(trim($data['starting_time']))) {
                $errors[] = 'Starting time is required.';
            } elseif (!$this->isValidDateTime($data['starting_time'])) {
                $errors[] = 'Starting time must be a valid datetime (YYYY-MM-DD HH:MM:SS).';
            }

            if (!isset($data['ending_time']) || empty(trim($data['ending_time']))) {
                $errors[] = 'Ending time is required.';
            } elseif (!$this->isValidDateTime($data['ending_time'])) {
                $errors[] = 'Ending time must be a valid datetime (YYYY-MM-DD HH:MM:SS).';
            }

            if (isset($data['starting_time']) && isset($data['ending_time'])) {
                if ($this->isValidDateTime($data['starting_time']) && $this->isValidDateTime($data['ending_time'])) {
                    $start = new \DateTime($data['starting_time']);
                    $end = new \DateTime($data['ending_time']);
                    
                    if ($end <= $start) {
                        $errors[] = 'Ending time must be after starting time.';
                    }
                }
            }
        }

        if (isset($data['overbidding']) && $data['overbidding'] !== null) {
            if ((int)$data['overbidding'] < 0) {
                $errors[] = 'Overbidding amount must be 0 or greater.';
            }
        }

        return $errors;
    }

    private function validateBidData(array $data): array
    {
        $errors = [];

        if (!isset($data['user_id']) || (int)$data['user_id'] <= 0) {
            $errors[] = 'Valid user_id is required.';
        }

        if (!isset($data['bid_amount']) || (float)$data['bid_amount'] <= 0) {
            $errors[] = 'Bid amount must be greater than 0.';
        }

        return $errors;
    }

    private function prepareAuctionData(array $data): array
    {
        return [
            'property_id' => (int)$data['property_id'],
            'starting_price' => (float)$data['starting_price'],
            'starting_time' => trim((string)$data['starting_time']),
            'ending_time' => trim((string)$data['ending_time']),
            'overbidding' => isset($data['overbidding']) ? (int)$data['overbidding'] : null,
        ];
    }

    private function prepareUpdateData(array $data): array
    {
        $allowedFields = ['starting_price', 'starting_time', 'ending_time', 'overbidding'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            if (in_array($field, ['starting_price'])) {
                $value = (float)$value;
            } elseif ($field === 'overbidding') {
                $value = $value !== null ? (int)$value : null;
            } elseif (in_array($field, ['starting_time', 'ending_time'])) {
                $value = trim((string)$value);
                if (!$this->isValidDateTime($value)) {
                    continue;
                }
            }

            $updateData[$field] = $value;
        }

        return $updateData;
    }

    private function formatAuctionResponse(array $auction): array
    {
        $currentPrice = $auction['current_price'] ?? $auction['starting_price'];
        $status = $this->determineAuctionStatus($auction['starting_time'], $auction['ending_time']);

        return [
            'id' => (int)$auction['id'],
            'property_id' => (int)$auction['property_id'],
            'property_title' => $auction['property_title'] ?? null,
            'address' => $auction['address'] ?? null,
            'city' => $auction['city'] ?? null,
            'postal' => $auction['postal'] ?? null,
            'starting_price' => (float)$auction['starting_price'],
            'current_price' => (float)$currentPrice,
            'starting_time' => $auction['starting_time'],
            'ending_time' => $auction['ending_time'],
            'overbidding' => $auction['overbidding'] !== null ? (int)$auction['overbidding'] : null,
            'bid_count' => isset($auction['bid_count']) ? (int)$auction['bid_count'] : 0,
            'status' => $status,
            'created_at' => $auction['created_at'],
            'updated_at' => $auction['updated_at']
        ];
    }

    private function determineAuctionStatus(string $startTime, string $endTime): string
    {
        $now = new \DateTime();
        $start = new \DateTime($startTime);
        $end = new \DateTime($endTime);

        if ($now < $start) {
            return 'upcoming';
        } elseif ($now > $end) {
            return 'ended';
        } else {
            return 'active';
        }
    }

    private function auctionExists(int $auctionID): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT id FROM auctions WHERE id = ?',
            [$auctionID]
        );
        
        return $result !== false;
    }

    private function propertyExists(int $propertyID): bool
    {
        return $this->entityExists('properties', $propertyID);
    }

    private function propertyHasActiveAuction(int $propertyID): bool
    {
        $now = date('Y-m-d H:i:s');
        
        $result = $this->db->fetchAssociative(
            'SELECT id FROM auctions 
             WHERE property_id = ? AND ending_time > ?',
            [$propertyID, $now]
        );
        
        return $result !== false;
    }

    private function getHighestBid(int $auctionID): ?float
    {
        $result = $this->db->fetchAssociative(
            'SELECT MAX(bid_amount) as highest_bid FROM bids WHERE auction_id = ?',
            [$auctionID]
        );
        
        return $result && $result['highest_bid'] !== null ? (float)$result['highest_bid'] : null;
    }

    private function isValidDateTime(string $datetime): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d && $d->format('Y-m-d H:i:s') === $datetime;
    }

    private function getLastInsertId(): ?int
    {
        try {
            return (int)$this->db->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}