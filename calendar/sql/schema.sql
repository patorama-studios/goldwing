CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    media_id INT DEFAULT NULL,
    scope ENUM('CHAPTER','NATIONAL') NOT NULL DEFAULT 'CHAPTER',
    chapter_id INT DEFAULT NULL,
    event_type ENUM('in_person','online','hybrid') NOT NULL DEFAULT 'in_person',
    timezone VARCHAR(64) NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    recurrence_rule VARCHAR(255) DEFAULT NULL,
    rsvp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    is_paid TINYINT(1) NOT NULL DEFAULT 0,
    ticket_product_id INT DEFAULT NULL,
    capacity INT DEFAULT NULL,
    sales_close_at DATETIME DEFAULT NULL,
    map_url VARCHAR(500) DEFAULT NULL,
    map_zoom INT DEFAULT NULL,
    online_url VARCHAR(500) DEFAULT NULL,
    meeting_point VARCHAR(255) DEFAULT NULL,
    destination VARCHAR(255) DEFAULT NULL,
    status ENUM('published','cancelled') NOT NULL DEFAULT 'published',
    cancellation_message VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS calendar_event_rsvps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    qty INT NOT NULL DEFAULT 1,
    notes VARCHAR(500) DEFAULT NULL,
    status ENUM('going','cancelled') NOT NULL DEFAULT 'going',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_user (event_id, user_id),
    INDEX idx_event_id (event_id)
);

CREATE TABLE IF NOT EXISTS calendar_event_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    qty INT NOT NULL DEFAULT 1,
    ticket_code VARCHAR(64) NOT NULL,
    ticket_pdf_url VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ticket_code (ticket_code),
    INDEX idx_event_id (event_id)
);

CREATE TABLE IF NOT EXISTS calendar_refund_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('pending','approved','declined','manual_required') NOT NULL DEFAULT 'pending',
    admin_id INT DEFAULT NULL,
    admin_notes VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    INDEX idx_event_id (event_id)
);

CREATE TABLE IF NOT EXISTS calendar_event_notifications_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    event_id INT DEFAULT NULL,
    type VARCHAR(64) NOT NULL,
    send_at DATETIME NOT NULL,
    payload_json TEXT DEFAULT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price_cents INT NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'aud'
);

CREATE TABLE IF NOT EXISTS calendar_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    stripe_session_id VARCHAR(255) DEFAULT NULL,
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    amount_cents INT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
