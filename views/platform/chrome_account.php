<?php /* Control-plane chrome, slot 'account' (app\Chrome, wired in lib/controlplane.php): Teams and Billing in the account menu. Included in the runtime header's scope. */ ?>
              <li><a class="dropdown-item" href="/teams"><i class="bi bi-people me-2"></i>Teams</a></li>
              <?php /* Account-scoped, so it belongs beside Profile/Settings rather than
                       in the project sidebar: billing follows the person, not whichever project
                       they happen to have open. Shown to every member — the page charges
                       nothing, and someone who cannot see what is counted against them has no
                       way to tell us it is wrong. */ ?>
              <li><a class="dropdown-item" href="/billing"><i class="bi bi-credit-card me-2"></i>Billing</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="/docs"><i class="bi bi-book me-2"></i>Documentation</a></li>
              <li><a class="dropdown-item" href="/help"><i class="bi bi-question-circle me-2"></i>Help</a></li>
