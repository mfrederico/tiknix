<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <h1 class="mb-4">Contact Support</h1>
            
            <?php if ($success ?? false): ?>
                <div class="alert alert-success">
                    <h4 class="alert-heading">Thank you for contacting us!</h4>
                    <p>Your message has been received and our support team will review it shortly.</p>
                    <hr>
                    <p class="mb-0">We typically respond within 24-48 hours. If your issue is urgent, please indicate that in your message.</p>
                </div>
                <a href="/" class="btn btn-primary">Return to Home</a>
            <?php else: ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h4 class="alert-heading">Please fix the following errors:</h4>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="/contact/submit">
                            <?php
                            // Include CSRF token if available
                            if (isset($csrf) && is_array($csrf)):
                                foreach ($csrf as $name => $value): ?>
                                    <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                                <?php endforeach;
                            endif;
                            ?>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Your Name <span class="text-danger">*</span></label>
                                <input type="text" 
                                       class="form-control" 
                                       id="name" 
                                       name="name" 
                                       value="<?= htmlspecialchars($data['name'] ?? $_SESSION['member']['first_name'] ?? '') ?>" 
                                       required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email" 
                                       value="<?= htmlspecialchars($data['email'] ?? $_SESSION['member']['email'] ?? '') ?>" 
                                       required>
                                <small class="form-text text-muted">We'll use this to respond to your inquiry</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="general" <?= ($data['category'] ?? '') === 'general' ? 'selected' : '' ?>>General Inquiry</option>
                                    <option value="support" <?= ($data['category'] ?? '') === 'support' ? 'selected' : '' ?>>Technical Support</option>
                                    <option value="billing" <?= ($data['category'] ?? '') === 'billing' ? 'selected' : '' ?>>Billing Question</option>
                                    <option value="feature" <?= ($data['category'] ?? '') === 'feature' ? 'selected' : '' ?>>Feature Request</option>
                                    <option value="bug" <?= ($data['category'] ?? '') === 'bug' ? 'selected' : '' ?>>Bug Report</option>
                                    <option value="other" <?= ($data['category'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                                <input type="text" 
                                       class="form-control" 
                                       id="subject" 
                                       name="subject" 
                                       value="<?= htmlspecialchars($data['subject'] ?? '') ?>" 
                                       required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="message" class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control" 
                                          id="message" 
                                          name="message" 
                                          rows="8" 
                                          required><?= htmlspecialchars($data['message'] ?? '') ?></textarea>
                                <small class="form-text text-muted">Please provide as much detail as possible</small>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-envelope-fill"></i> Send Message
                                </button>
                                <a href="/" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="mt-4 text-center text-muted">
                    <p><i class="bi bi-info-circle"></i> Need immediate assistance? Check our <a href="/help">Help Center</a> for quick answers.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>