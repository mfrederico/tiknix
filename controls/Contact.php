<?php
/**
 * Contact Controller
 * Handles contact form submissions and viewing
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\Mailer;

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

        // Bot defences, in order of cheapness. This form took 189 submissions before it
        // had any: it is public by design (a person who cannot log in still needs to
        // reach support), Contact::submit() validated no CSRF token at all, and
        // csrf_enabled is false globally anyway — so a bare POST to /contact/submit from
        // anywhere worked, forever, at any rate.
        //
        // None of these stop a determined human, and none of them are meant to. They stop
        // the automated volume, which is all of what was in that table.

        // 1. Honeypot: a field a person never sees and a form-filler always completes.
        if (trim((string)($request->data->website ?? '')) !== '') {
            Flight::get('log')->info('Contact form honeypot tripped', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'email' => (string)($request->data->email ?? ''),
            ]);
            // Answer exactly as success does. Telling a bot why it failed teaches it.
            $this->render('contact/form', ['title' => 'Contact Support', 'success' => true]);
            return;
        }

        // 2. Proof the form was actually loaded, and how long it was open for.
        //
        //    REQUIRED, not "checked when present" — that distinction is the whole defence.
        //    The bot this form actually attracts does not scrape the page; it POSTs
        //    straight to /contact/submit with the four fields it already knows. A check
        //    that skips when the field is missing is no check at all against exactly that.
        //
        //    A person always has this field, because the rendered form always emits it.
        $rendered = (int)($request->data->form_ts ?? 0);
        $age      = $rendered > 0 ? time() - $rendered : -1;
        if ($rendered <= 0 || $age < 3 || $age > 86400) {
            Flight::get('log')->info('Contact form rejected: no proof the form was loaded', [
                'form_ts' => $rendered, 'age' => $age, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'email' => (string)($request->data->email ?? ''),
            ]);
            $this->render('contact/form', ['title' => 'Contact Support', 'success' => true]);
            return;
        }

        // 3. Rate limit per IP. A real person does not file four support requests an hour.
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip !== '') {
            $since  = date('Y-m-d H:i:s', time() - 3600);
            $recent = (int) Bean::count('contact', 'ip_address = ? AND created_at >= ?', [$ip, $since]);
            if ($recent >= 3) {
                Flight::get('log')->warning('Contact form rate limit hit', ['ip' => $ip, 'recent' => $recent]);
                $this->render('contact/form', [
                    'title'  => 'Contact Support',
                    'errors' => ['You have sent several messages recently. Please give us a little time to reply before sending another.'],
                    'data'   => $request->data->getData(),
                ]);
                return;
            }
        }

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
            $contact = Bean::dispense('contact');
            $contact->name = $name;
            $contact->email = $email;
            $contact->subject = $subject;
            $contact->message = $message;
            $contact->category = $category ?: 'general';
            $contact->status = 'new';
            $contact->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $contact->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // If user is logged in, link to their account
            if (isset($_SESSION['member']['id'])) {
                $contact->memberId = $_SESSION['member']['id'];
            }

            $contact->createdAt = date('Y-m-d H:i:s');
            $contactId = (int) Bean::store($contact);

            // Log the submission
            Flight::get('log')->info('Contact form submitted', [
                'from' => $email,
                'subject' => $subject
            ]);

            // ...and tell somebody. Saving the row is not delivery: this table held five
            // months of messages at status "new" because arrival was announced nowhere.
            $this->alertOperators($contactId, $name, $email, $subject, $message,
                                  $category ?: 'general', !empty($contact->memberId));

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
     * Which operator's inbox support threads land in: the most senior active admin.
     *
     * Deliberately the same person the alert email goes to when [mail] support_email is
     * unset, so the mail and the thread do not end up with different owners.
     */
    private function supportOwnerId(): int {
        $admin = Bean::findOne('member',
            'level <= ? AND status = ? ORDER BY level ASC, id ASC',
            [LEVELS['ADMIN'], 'active']);
        return (int) ($admin->id ?? 0);
    }

    /**
     * Where support mail should land: an explicitly configured address, or failing that
     * the most senior active admin. Returns '' when there is nobody to tell.
     */
    private function supportAddress(): string {
        $configured = trim((string) (Flight::get('mail.support_email') ?? ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) return $configured;

        $admin = Bean::findOne('member',
            'level <= ? AND status = ? ORDER BY level ASC, id ASC',
            [LEVELS['ADMIN'], 'active']);
        $email = trim((string) ($admin->email ?? ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Announce a new support message. Never throws and never blocks the submission — the
     * message is already stored, so the visitor is done either way — but a failure here is
     * logged at ERROR, because "we could not tell anyone" is exactly the kind of quiet
     * breakage that let this table fill up unread in the first place.
     */
    private function alertOperators(
        int $contactId, string $name, string $email, string $subject,
        string $message, string $category, bool $fromMember
    ): void {
        // First, into the inbox. A support message IS a message, and Communications is
        // where messages live — an emailed alert about a row in a table is a notification
        // ABOUT the thing rather than the thing itself. The thread is owned by the
        // operator and addressed to the sender, so replying to it answers them through
        // the ordinary reply path.
        try {
            $owner = $this->supportOwnerId();
            if ($owner > 0) {
                $threadId = \app\services\NotifyService::openInboundThread(
                    $owner, $email, $name,
                    '[' . $category . '] ' . $subject,
                    nl2br(htmlspecialchars($message, ENT_QUOTES)),
                    'contact', $contactId
                );
                if ($threadId) {
                    Flight::get('log')->info('Support message threaded into Communications', [
                        'contact_id' => $contactId, 'thread' => $threadId, 'owner' => $owner,
                    ]);
                } else {
                    Flight::get('log')->error('Support message could not open a thread', [
                        'contact_id' => $contactId, 'owner' => $owner,
                    ]);
                }
            } else {
                Flight::get('log')->error('Support message saved but there is no operator to own it', [
                    'contact_id' => $contactId,
                ]);
            }
        } catch (\Throwable $e) {
            Flight::get('log')->error('Support message threading threw', [
                'contact_id' => $contactId, 'error' => $e->getMessage(),
            ]);
        }

        // Then the email, because nobody watches an inbox they are not signed into.
        try {
            $to = $this->supportAddress();
            if ($to === '') {
                Flight::get('log')->error('Support message saved but nobody to notify', [
                    'contact_id' => $contactId,
                    'hint' => 'set [mail] support_email, or give an admin account a valid email',
                ]);
                return;
            }
            if (!Mailer::isConfigured()) {
                Flight::get('log')->error('Support message saved but mail is not configured', [
                    'contact_id' => $contactId, 'would_have_told' => $to,
                ]);
                return;
            }

            $sent = Mailer::sendContactAlert($to, $name, $email, $category, $subject,
                                             $message, $contactId, $fromMember);
            if ($sent) {
                Flight::get('log')->info('Support message notification sent', [
                    'contact_id' => $contactId, 'to' => $to,
                ]);
            } else {
                Flight::get('log')->error('Support message notification FAILED to send', [
                    'contact_id' => $contactId, 'to' => $to,
                ]);
            }
        } catch (\Throwable $e) {
            Flight::get('log')->error('Support message notification threw', [
                'contact_id' => $contactId, 'error' => $e->getMessage(),
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
        $total = Bean::count('contact', $where, $params);
        
        // Get messages with parameterized LIMIT and OFFSET
        $offset = ($page - 1) * $perPage;
        $sql = ($where ? $where . ' ' : '') . "ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        $messages = Bean::findAll('contact', $sql, $params);
        
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
        
        $message = Bean::load('contact', $id);
        if (!$message->id) {
            $this->flash('error', 'Message not found');
            Flight::redirect('/contact/admin');
            return;
        }

        // Mark as read if new
        if ($message->status === 'new') {
            $message->status = 'read';
            $message->readAt = date('Y-m-d H:i:s');
            Bean::store($message);
        }

        // Get member info if linked
        $member = null;
        if ($message->memberId) {
            $member = Bean::load('member', $message->memberId);
        }
        
        // Get responses via association with ordering
        $responses = $message->with(' ORDER BY created_at DESC ')->ownContactresponseList;
        
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
        
        $message = Bean::load('contact', $messageId);
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
            $response = Bean::dispense('contactresponse');
            $response->contactId = $messageId;
            $response->adminId = $_SESSION['member']['id'];
            $response->response = $responseText;
            $response->createdAt = date('Y-m-d H:i:s');
            Bean::store($response);

            // Update message status
            $message->status = $status;
            $message->respondedAt = date('Y-m-d H:i:s');
            $message->respondedBy = $_SESSION['member']['id'];
            Bean::store($message);

            // Reply ON THE THREAD, not as a standalone email.
            //
            // This used to go out through Mailer::sendContactResponse(), which sends a
            // perfectly good message carrying no reply token. controls/Webhook.php routes
            // inbound mail back to a conversation by matching reply-{token}@ — so with no
            // token, when the person answered, their answer matched nothing and was lost.
            // Support was one-way and did not look it.
            //
            // NotifyService puts the reply on the thread this contact row already owns
            // (relatedTo finds it), which means the outbound carries the token and their
            // reply comes back into the same conversation by itself.
            $adminName = member_display_name($_SESSION['member'] ?? null, 'Support');
            $html = nl2br(htmlspecialchars($responseText, ENT_QUOTES));

            $result = \app\services\NotifyService::create()
                ->to((string)$message->email, (string)$message->name)
                ->subject((string)$message->subject)
                ->owner((int)($_SESSION['member']['id'] ?? 0))
                ->relatedTo('contact', (int)$message->id)
                ->fromName($adminName)
                ->send($html);

            $sent = !empty($result['sent']);
            if ($sent) {
                $response->emailSent   = 1;
                $response->emailSentAt = date('Y-m-d H:i:s');
                Bean::store($response);
            } else {
                // Saved but not delivered is a state somebody has to know about — the
                // person is waiting on an answer that never left the building.
                Flight::get('log')->error('Support reply saved but NOT delivered', [
                    'contact' => (int)$message->id,
                    'thread'  => (int)($result['thread'] ?? 0),
                    'error'   => (string)($result['error'] ?? 'unknown'),
                ]);
            }

            $this->flash($sent ? 'success' : 'error', $sent
                ? 'Response sent'
                : 'Your response was saved but could NOT be delivered: ' . (string)($result['error'] ?? 'unknown'));
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
        
        $message = Bean::load('contact', $id);
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
        $message->updatedAt = date('Y-m-d H:i:s');
        Bean::store($message);
        
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
        
        $message = Bean::load('contact', $id);
        if (!$message->id) {
            $this->flash('error', 'Message not found');
            Flight::redirect('/contact/admin');
            return;
        }

        // Use xown for cascade delete - responses are auto-deleted with message
        // Accessing xownContactresponseList tells RedBeanPHP to cascade delete
        $message->xownContactresponseList;
        Bean::trash($message);
        
        $this->flash('success', 'Message deleted');
        Flight::redirect('/contact/admin');
    }
}