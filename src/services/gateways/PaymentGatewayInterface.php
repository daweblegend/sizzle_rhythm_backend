<?php
/**
 * Payment Gateway Interface
 * Defines the contract that all payment gateways must implement
 * 
 * @author Sizzle & Rhythm Team
 * @version 1.0
 */

interface PaymentGatewayInterface {
    
    /**
     * Initialize payment transaction
     * 
     * @param array $data Payment data
     * @return array Payment initialization response
     */
    public function initializePayment(array $data);
    
    /**
     * Verify payment transaction
     * 
     * @param string $reference Transaction reference
     * @return array Payment verification response
     */
    public function verifyPayment(string $reference);
    
    /**
     * Handle webhook from gateway
     * 
     * @param array $payload Webhook payload
     * @return array Webhook response
     */
    public function handleWebhook(array $payload);
    
    /**
     * Get supported payment methods
     * 
     * @return array
     */
    public function getSupportedMethods();
    
    /**
     * Get supported currencies
     * 
     * @return array
     */
    public function getSupportedCurrencies();
}
