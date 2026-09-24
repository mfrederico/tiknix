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
        // A signed-in member asks from inside the app: no name, email or bot check to fill
        // in (they are signed in), and the answer comes back to their Communications.
        if (!empty($this->member->id)) {
            $this->render('contact/member', [
                'title'   => 'Support',
                'project' => ProjectContext::current((int) $this->member->id),
                'tickets' => array_map(fn($c) => [
                    'ticket' => $c,
                    'thread' => (int) (Bean::findOne('thread', 'related_type = ? AND related_id = ?', ['contact', (int) $c->id])->id ?? 0),
                ], array_values(Bean::find('contact', 'member_id = ? ORDER BY id DESC LIMIT 20', [(int) $this->member->id]))),
            ]);
            return;
        }
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

        // The real HTTP verb. A form submission is a POST; anything else has nothing to submit.
        // ($request->method is not asked: Flight derives it from ?_method= too, and
        // SimpleCsrf waves a real GET through — see Control::validateCSRF().)
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            Flight::redirect('/contact');
            return;
        }

        // Bot defences, in order of cheapness. This form took 189 submissions before it
        // had any: it is public by design (a person who cannot log in still needs to
        // reach support), and for a long time it validated no CSRF token either — so a bare
        // POST to /contact/submit from anywhere worked, forever, at any rate.
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

        // 2a. CSRF. The form has always emitted the token; this is the half that was missing.
        //
        //     Answered DIFFERENTLY from the checks around it, on purpose. Those are tripped
        //     by bots, so they answer "thanks" and teach nothing. This one is also tripped by
        //     a real person: the form may sit open for a day (form_ts allows it) and a session
        //     lasts about as long, so somebody who wrote a long message and came back to send
        //     it has no matching token through no fault of their own. Control::validateCSRF()
        //     would send them to a Forbidden page and throw the message away — the one
        //     outcome a support form must not have. So: say what happened, keep what they
        //     typed, and let them send again. The re-rendered form carries this session's
        //     token, so the second attempt goes through.
        //
        //     Before Turnstile, so a failure here does not spend a verification.
        if (!SimpleCsrf::validate()) {
            Flight::get('log')->info('Contact form rejected: CSRF token missing or stale', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'email' => (string)($request->data->email ?? ''),
                'had_token' => isset($_POST['_csrf_token']),
            ]);
            $this->render('contact/form', [
                'title'  => 'Contact Support',
                'errors' => ['Your session expired before this was sent, so we could not accept it. Your message is still here — please send it again.'],
                'data'   => $request->data->getData(),
            ]);
            return;
        }

        // 2b. Cloudflare Turnstile. A no-op when Turnstile isn't configured for this install
        //     (verify() returns true), so it only bites where the widget actually rendered.
        //     Answered as success on failure, same as the honeypot: a bot told "thanks" stops.
        if (!\app\Turnstile::verify($this->getParam(\app\Turnstile::FIELD, null), (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
            Flight::get('log')->info('Contact form rejected: Turnstile failed', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'email' => (string)($request->data->email ?? ''),
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
     * POST /contact/ask — a signed-in member writes to support. Same queue as the public
     * form (/contact/admin, the Support badge, the alert email), but the member is seated
     * in the conversation, so the answer and any follow-up happen in Communications.
     * No Turnstile: they are signed in, and the form carries a CSRF token.
     */
    public function ask() {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $subject  = trim((string) $this->getParam('subject', ''));
        $message  = trim((string) $this->getParam('message', ''));
        $category = (string) $this->getParam('category', 'general');
        if (!in_array($category, ['general', 'problem', 'billing', 'feature'], true)) $category = 'general';
        if ($subject === '' || $message === '') {
            $this->flash('error', 'A subject and a message are both needed.');
            Flight::redirect('/contact');
            return;
        }
        $mid = (int) $this->member->id;
        // Which project it is about, when one is selected: said in the message, so whoever
        // answers does not have to ask.
        $project = ProjectContext::current($mid);
        if ($project && (int) $this->getParam('about_project', 0) === (int) $project->id) {
            $message = "Project: " . ($project->displayName ?: $project->slug) . " ({$project->slug})\n\n" . $message;
        }

        $contact = Bean::dispense('contact');
        $contact->name      = $this->member->displayName('Member #' . $mid);
        $contact->email     = (string) $this->member->email;
        $contact->subject   = $subject;
        $contact->message   = $message;
        $contact->category  = $category;
        $contact->status    = 'new';
        $contact->memberId  = $mid;
        $contact->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $contact->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $contact->createdAt = date('Y-m-d H:i:s');
        $contactId = (int) Bean::store($contact);
        Flight::get('log')->info('Member support message', ['member' => $mid, 'contact_id' => $contactId, 'subject' => $subject]);

        $threadId = $this->alertOperators($contactId, (string) $contact->name, (string) $contact->email, $subject, $message, $category, true);
        if (!$threadId) {
            // Stored and in the admin queue, but not a conversation the member can open.
            $this->flash('warning', 'Your message reached support, but its conversation could not be opened — you will get the answer by email.');
            Flight::redirect('/contact');
            return;
        }
        \app\ThreadMembers::ensure($threadId, [$mid]);
        \app\ThreadMembers::markRead($threadId, $mid);
        $this->flash('success', 'Sent to support. The answer will appear here and in your email.');
        Flight::redirect('/communications/thread/' . $threadId);
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
     *
     * @return int|null the support conversation's id, null when none could be opened
     */
    private function alertOperators(
        int $contactId, string $name, string $email, string $subject,
        string $message, string $category, bool $fromMember
    ): ?int {
        $threadId = null;
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
                    $threadId = (int) $threadId;
                    // Seat the whole support team, not just one operator. Support is a
                    // queue somebody answers, not one person's mail — and per-person
                    // unread means each of them tracks their own reading of it without
                    // clearing anybody else's.
                    $admins = array_values(array_map(
                        fn($a) => (int) $a->id,
                        Bean::find('member', 'level <= ? AND status = ?', [LEVELS['ADMIN'], 'active'])
                    ));
                    \app\ThreadMembers::ensure($threadId, $admins);

                    Flight::get('log')->info('Support message threaded into Communications', [
                        'contact_id' => $contactId, 'thread' => $threadId, 'owner' => $owner,
                        'team' => count($admins),
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
                return $threadId ?: null;
            }
            if (!Mailer::isConfigured()) {
                Flight::get('log')->error('Support message saved but mail is not configured', [
                    'contact_id' => $contactId, 'would_have_told' => $to,
                ]);
                return $threadId ?: null;
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
        return $threadId ?: null;
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

        // The account that submitted this message, if any — NOT the admin reading it.
        // Named apart from 'member' on purpose: that key carries the signed-in viewer
        // for the whole app shell, and overwriting it here is what 500'd this page.
        $contactMember = null;
        if ($message->memberId) {
            $contactMember = Bean::load('member', $message->memberId);
        }
        
        // Get responses via association with ordering
        $responses = $message->with(' ORDER BY created_at DESC ')->ownContactresponseList;
        
        $this->render('contact/view', [
            'title' => 'View Message',
            'message' => $message,
            'contactMember' => $contactMember,
            'responses' => $responses
        ]);
    }
    
    /**
     * Admin: Respond to a message
     */
    public function respond() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->requirePost()) return;

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

            // Reply ON THE THREAD this ticket owns (lib/Notes.php): in the app, signed by
            // the admin, and by email with the conversation's reply token, so their answer
            // comes back into the same conversation. A member who asked from inside the app
            // is seated in it and sees the reply in Communications too.
            //
            // A ticket with no conversation (its threading failed when it arrived) gets one
            // now, from its original message, so the reply still has a place to live.
            $thread = Bean::findOne('thread', 'related_type = ? AND related_id = ?', ['contact', (int)$message->id]);
            $threadId = $thread && $thread->id ? (int)$thread->id : (int)\app\services\NotifyService::openInboundThread(
                (int)$_SESSION['member']['id'], (string)$message->email, (string)$message->name,
                '[' . ((string)$message->category ?: 'general') . '] ' . (string)$message->subject,
                nl2br(htmlspecialchars((string)$message->message, ENT_QUOTES)), 'contact', (int)$message->id);
            if ($threadId <= 0) throw new \RuntimeException("support ticket #{$message->id} has no conversation and one could not be opened");

            $result = \app\Notes::onThread((int)$_SESSION['member']['id'], $threadId,
                nl2br(htmlspecialchars($responseText, ENT_QUOTES)), (string)$message->subject);

            $sent = $result['email'] === 'sent';
            if ($sent) {
                $response->emailSent   = 1;
                $response->emailSentAt = date('Y-m-d H:i:s');
                Bean::store($response);
            } else {
                // Saved but not delivered is a state somebody has to know about — the
                // person is waiting on an answer that never left the building.
                Flight::get('log')->error('Support reply saved but NOT emailed', [
                    'contact' => (int)$message->id,
                    'thread'  => $threadId,
                    'email'   => $result['email'],
                    'error'   => (string)($result['email_error'] ?? ''),
                ]);
            }
            $this->flash($sent ? 'success' : 'error', $sent
                ? 'Response sent'
                : 'Your response is in the conversation, but the email was NOT sent (' . $result['email'] . '): ' . (string)($result['email_error'] ?? 'no address on the ticket'));
            Flight::redirect('/contact/view?id=' . $messageId);
            
        } catch (\Throwable $e) {
            Flight::get('log')->error('Contact response error: ' . $e->getMessage());
            $this->flash('error', 'Failed to send the response: ' . $e->getMessage());
            Flight::redirect('/contact/view?id=' . $messageId);
        }
    }
    
    /**
     * Admin: Update message status
     */
    public function status() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->requirePost()) return;

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
        if (!$this->requirePost()) return;

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