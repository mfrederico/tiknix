<?php
/**
 * Contact Controller
 * Handles contact form submissions and viewing
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class Contact extends BaseControls\Control {
    
    /**
     * Display contact form
     */
    public function index() {
        $this->render('contact/form', [
            'title' => 'Contact Support',
            'success' => false
        ]);
    }
    
    /**
     * Process contact form submission
     */
    public function submit() {
        $request = Flight::request();
        
        // Get form data
        $name = $this->sanitize($request->data->name);
        $email = $this->sanitize($request->data->email, 'email');
        $subject = $this->sanitize($request->data->subject);
        $message = $this->sanitize($request->data->message);
        $category = $this->sanitize($request->data->category);
        
        // Validate required fields
        $errors = [];
        if (empty($name)) $errors[] = 'Name is required';
        if (empty($email)) $errors[] = 'Email is required';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
        if (empty($subject)) $errors[] = 'Subject is required';
        if (empty($message)) $errors[] = 'Message is required';
        
        if (!empty($errors)) {
            $this->render('contact/form', [
                'title' => 'Contact Support',
                'errors' => $errors,
                'data' => $request->data->getData()
            ]);
            return;
        }
        
        // Save to database
        try {
            $contact = R::dispense('contact');
            $contact->name = $name;
            $contact->email = $email;
            $contact->subject = $subject;
            $contact->message = $message;
            $contact->category = $category ?: 'general';
            $contact->status = 'new';
            $contact->ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $contact->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // If user is logged in, link to their account
            if (isset($_SESSION['member']['id'])) {
                $contact->member_id = $_SESSION['member']['id'];
            }
            
            $contact->created_at = date('Y-m-d H:i:s');
            R::store($contact);
            
            // Log the submission
            Flight::get('log')->info('Contact form submitted', [
                'from' => $email,
                'subject' => $subject
            ]);
            
            // Show success message
            $this->render('contact/form', [
                'title' => 'Contact Support',
                'success' => true
            ]);
            
        } catch (\Exception $e) {
            Flight::get('log')->error('Contact form error: ' . $e->getMessage());
            $this->render('contact/form', [
                'title' => 'Contact Support',
                'errors' => ['An error occurred. Please try again later.'],
                'data' => $request->data->getData()
            ]);
        }
    }
    
    /**
     * Admin: View all contact messages
     */
    public function admin() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        
        $request = Flight::request();
        $page = (int)($request->query->page ?? 1);
        $status = $request->query->status ?? 'all';
        $perPage = 20;
        
        // Build query
        $where = '';
        $params = [];
        if ($status !== 'all') {
            $where = 'status = ?';
            $params[] = $status;
        }
        
        // Get total count
        $total = R::count('contact', $where, $params);
        
        // Get messages with parameterized LIMIT and OFFSET
        $offset = ($page - 1) * $perPage;
        $sql = ($where ? $where . ' ' : '') . "ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        $messages = R::findAll('contact', $sql, $params);
        
        $this->render('contact/admin', [
            'title' => 'Contact Messages',
            'messages' => $messages,
            'page' => $page,
            'total' => $total,
            'perPage' => $perPage,
            'status' => $status
        ]);
    }
    
    /**
     * Admin: View single message
     */
    public function view() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        
        $request = Flight::request();
        $id = $request->query->id ?? 0;
        
        $message = R::load('contact', $id);
        if (!$message->id) {
            $this->flash('error', 'Message not found');
            Flight::redirect('/contact/admin');
            return;
        }
        
        // Mark as read if new
        if ($message->status === 'new') {
            $message->status = 'read';
            $message->read_at = date('Y-m-d H:i:s');
            R::store($message);
        }
        
        // Get member info if linked
        $member = null;
        if ($message->member_id) {
            $member = R::load('member', $message->member_id);
        }
        
        // Get responses
        $responses = R::find('contactresponse', 
            'contact_id = ? ORDER BY created_at DESC', 
            [$message->id]
        );
        
        $this->render('contact/view', [
            'title' => 'View Message',
            'message' => $message,
            'member' => $member,
            'responses' => $responses
        ]);
    }
    
    /**
     * Admin: Respond to a message
     */
    public function respond() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        
        $request = Flight::request();
        $messageId = $request->data->message_id ?? 0;
        $responseText = $this->sanitize($request->data->response);
        $status = $request->data->status ?? 'responded';
        
        $message = R::load('contact', $messageId);
        if (!$message->id) {
            $this->flash('error', 'Message not found');
            Flight::redirect('/contact/admin');
            return;
        }
        
        if (empty($responseText)) {
            $this->flash('error', 'Response cannot be empty');
            Flight::redirect('/contact/view?id=' . $messageId);
            return;
        }
        
        try {
            // Save response
            $response = R::dispense('contactresponse');
            $response->contact_id = $messageId;
            $response->admin_id = $_SESSION['member']['id'];
            $response->response = $responseText;
            $response->created_at = date('Y-m-d H:i:s');
            R::store($response);
            
            // Update message status
            $message->status = $status;
            $message->responded_at = date('Y-m-d H:i:s');
            $message->responded_by = $_SESSION['member']['id'];
            R::store($message);
            
            // TODO: Send email to user if configured
            
            $this->flash('success', 'Response sent successfully');
            Flight::redirect('/contact/view?id=' . $messageId);
            
        } catch (\Exception $e) {
            Flight::get('log')->error('Contact response error: ' . $e->getMessage());
            $this->flash('error', 'Failed to save response');
            Flight::redirect('/contact/view?id=' . $messageId);
        }
    }
    
    /**
     * Admin: Update message status
     */
    public function status() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        
        $request = Flight::request();
        $id = $request->data->id ?? 0;
        $status = $request->data->status ?? '';
        
        $message = R::load('contact', $id);
        if (!$message->id) {
            $this->json(['success' => false, 'error' => 'Message not found']);
            return;
        }
        
        $validStatuses = ['new', 'read', 'responded', 'closed', 'spam'];
        if (!in_array($status, $validStatuses)) {
            $this->json(['success' => false, 'error' => 'Invalid status']);
            return;
        }
        
        $message->status = $status;
        $message->updated_at = date('Y-m-d H:i:s');
        R::store($message);
        
        $this->json(['success' => true]);
    }
    
    /**
     * Admin: Delete message
     */
    public function delete() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        
        $request = Flight::request();
        $id = $request->data->id ?? 0;
        
        $message = R::load('contact', $id);
        if (!$message->id) {
            $this->flash('error', 'Message not found');
            Flight::redirect('/contact/admin');
            return;
        }
        
        // Delete related responses first
        R::exec('DELETE FROM contactresponse WHERE contact_id = ?', [$id]);
        
        // Delete message
        R::trash($message);
        
        $this->flash('success', 'Message deleted');
        Flight::redirect('/contact/admin');
    }
}