
<?php
$biz = 'ShipCannon';
$parent = [
    'name' => 'ClickSimple INC',
    'phone' => '(866) 845-7447',
    'contact' => 'hello@clicksimple.com',
    'state' => 'North Carolina',
    'city' => 'Charlotte',
    'contactaddress' => "Mooresville, North Carolina,\n28115\nUSA",
];
?>

<style>
    .legal-hero {
        background: linear-gradient(135deg, var(--dark-color) 0%, #1a1f2e 50%, var(--dark-color) 100%);
        padding: 120px 0 60px;
    }
    .legal-content {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 3rem;
        margin-top: -30px;
        position: relative;
        z-index: 10;
    }
    .legal-content h2 {
        color: #fff;
        font-weight: 700;
        font-size: 1.5rem;
        margin-top: 2.5rem;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--dark-border);
    }
    .legal-content h3 {
        color: #fff;
        font-weight: 600;
        font-size: 1.2rem;
        margin-top: 1.5rem;
        margin-bottom: 0.75rem;
    }
    .legal-content p, .legal-content li {
        color: var(--text-primary);
        line-height: 1.8;
        font-size: 0.95rem;
    }
    .legal-content ul, .legal-content ol {
        padding-left: 1.5rem;
        margin-bottom: 1.25rem;
    }
    .legal-content li { margin-bottom: 0.5rem; }
    @media (max-width: 768px) {
        .legal-content { padding: 1.5rem; }
        .legal-hero { padding: 80px 0 40px; }
    }
</style>

<section class="legal-hero">
    <div class="container text-center position-relative">
        <h1 style="font-size: 2.75rem; font-weight: 900; color: #fff;">Terms of Service</h1>
        <p style="color: var(--text-secondary); margin-top: 1rem;">Last updated: March 18, 2022</p>
    </div>
</section>

<section class="section-dark" style="padding: 40px 0 80px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="legal-content">

                    <p><?= $biz ?> services may also include the receipt, storage, picking, shipment, and related administrative functions, including a license to all related <?= $biz ?> Software and documentation (collectively, the "Service(s)"). These Services may require Customer to deliver data to the <?= $biz ?> data center. <?= $biz ?> provides its services subject to the terms and conditions contained in these Terms of Service (this "Agreement"). By signing up for the service you accept the terms of this Agreement. Please review the terms of this Agreement carefully. Once accepted, this Agreement becomes a binding legal commitment. If you have any questions, you can reach the <?= $biz ?> team at <?= $parent['contact'] ?>.</p>

                    <p>The Service Fees will be set forth in your Customer Dashboard account.</p>

                    <h2>Terms and Conditions</h2>

                    <p>The following terms, when used in this Agreement shall have the following meanings:</p>
                    <ul>
                        <li><strong>"<?= $biz ?> Software"</strong> means either an application programming interface or cloud software for the Services (or feature of the Services) provided to Customer by <?= $biz ?>.</li>
                        <li><strong>"Customer Application"</strong> means a software application that interfaces with the Services using the <?= $biz ?> Software and includes any services (web-based or other services) made available by Customer in connection with that application.</li>
                        <li><strong>"Customer Data"</strong> means data and other information made available to <?= $biz ?> through the use of the Services under this Agreement.</li>
                        <li><strong>"Documentation"</strong> means all of the instructions, code samples, on-line help files and technical documentation made available by <?= $biz ?> for the Services.</li>
                        <li><strong>"End User"</strong> means an end user of a Customer Application.</li>
                    </ul>

                    <h2>1. Services; Grant of Rights</h2>
                    <ol>
                        <li>Subject to the terms of this Agreement, <?= $biz ?> agrees to provide Customer the Services. <?= $biz ?> will provide adequate assurances to efficiently and carefully handle Customer's data and maintain security. <?= $biz ?> will provide, at its sole cost and expense, all utilities necessary to operate the data center Facility and may move Customer data between Facilities securely. <?= $biz ?> shall provide adequate security for the Facilities and contents thereof.</li>
                        <li>Subject to the terms of this Agreement, <?= $biz ?> grants Customer a non-exclusive, non-sublicensable, non-transferable, revocable right to:
                            <ul>
                                <li>Use the Documentation and <?= $biz ?> Software as needed to develop Customer Applications;</li>
                                <li>Use the Services through the <?= $biz ?> Application; and</li>
                                <li>Offer and make the Services available to End Users through Customer Applications, in accordance with the Documentation.</li>
                            </ul>
                        </li>
                        <li>Customer will be solely responsible for all use (whether or not authorized) of the Services and Documentation under its account, including for the quality and integrity of Customer Data and each Customer Application. Customer will take all reasonable precautions to prevent unauthorized access to or use of the Services and notify <?= $biz ?> promptly of any such unauthorized access or use.</li>
                        <li>Customer acknowledges that the features and functions of the Services, including the <?= $biz ?> Software, may change over time. It is Customer's responsibility to ensure that calls or requests Customer makes to the Services are compatible with then-current <?= $biz ?> Software. Although <?= $biz ?> endeavors to avoid changes to the <?= $biz ?> Software that are not backwards compatible, if any such changes become necessary <?= $biz ?> will use reasonable efforts to notify Customer at least 60 days prior to implementation.</li>
                        <li>Customer agrees to ship all packages in their correct postal class and using accurate information. In the event that Company discovers that a shipment is incorrectly classified or the weight or dimensions differ, Company reserves the right to re-bill Customer for the correct transportation costs and any additional costs and surcharges associated with such shipment.</li>
                    </ol>

                    <h2>2. Restrictions; Responsibilities</h2>
                    <ol>
                        <li>Except as expressly provided in Section 1, Customer will not transfer, resell, lease, license or otherwise make available the Services to third parties. Further, Customer will not offer the Services on a standalone basis under any circumstance. Customer will ensure that the Services are used in accordance with all applicable laws, regulations, third party rights and <?= $biz ?> policies, as well as the terms of this Agreement. Except to the extent applicable law prohibits such restrictions, Customer will not (and will not permit any third party to), directly or indirectly: reverse engineer, decompile, disassemble or otherwise attempt to discover the source code, object code or underlying structure, ideas, know-how or algorithms relevant to the Services.</li>
                        <li>Customer shall be responsible for obtaining and maintaining any equipment and ancillary services needed to connect to, access or otherwise use the Services, including, without limitation, modems, hardware, server, software, operating system, networking, web servers and the like (collectively, "Equipment"). Customer shall also be responsible for maintaining the security of the Equipment, Customer account, passwords and files.</li>
                        <li>Customer will defend, indemnify and hold <?= $biz ?> harmless against any actual or threatened claim, loss, liability, action, proceeding, third-party discovery demand, governmental investigation or enforcement action arising out of or relating to Customer's activities under this Agreement.</li>
                    </ol>

                    <h2>3. IP Rights; Confidentiality</h2>
                    <ol>
                        <li>As between the parties, <?= $biz ?> exclusively owns and reserves all right, title and interest in and to the Services and <?= $biz ?> Confidential Information and all related intellectual property rights. As between the parties, Customer exclusively owns and reserves all right, title and interest in and to the Customer Data, Customer Applications and Customer Confidential Information, and all related intellectual property rights.</li>
                        <li>Subject to the terms of this Agreement, each party grants to the other party the right to use and display its name and marks on its website and in other promotional materials solely in connection with its activities under this Agreement.</li>
                        <li>"Confidential Information" means any information or data, regardless of whether it is in tangible form, disclosed by either party that is marked or otherwise designated as confidential or proprietary or that should otherwise be reasonably understood to be confidential given the nature of the information and the circumstances surrounding disclosure.</li>
                        <li>Each party agrees that it will use the Confidential Information of the other party solely in accordance with the provisions of this Agreement and it will not disclose such information to any third party without the other party's prior written consent, except as otherwise permitted hereunder.</li>
                        <li><?= $biz ?> shall have the right to collect and analyze data and other information relating to the provision, use and performance of various aspects of the Services and related systems and technologies, and <?= $biz ?> will be free to use such information to improve and enhance the Services and disclose such data solely in aggregate or other de-identified form in connection with its business.</li>
                    </ol>

                    <h2>4. Payment of Fees</h2>
                    <ol>
                        <li>Customer agrees to pay all applicable Services Fees and the prices for postage rates that are returned via the <?= $biz ?> Software. <?= $biz ?> reserves the right to change the Services Fees or applicable charges and to institute new charges, upon 7 days notice.</li>
                        <li>Unless otherwise stated, all Services Fees are exclusive of applicable federal, state or local taxes and all such taxes, fees and charges will be the sole responsibility of and payable by Customer. If credit card payment is selected, standard credit card fees (3%) apply.</li>
                        <li>Customers are invoiced monthly for credit card payments and quarterly for payments made via wire transfer or ACH. If credit card payment is not received within fifteen (15) days of the invoice, or wire transfer or ACH payment is not received within thirty (30) days of the invoice, the <?= $biz ?> Service will be terminated.</li>
                        <li>Customer will notify <?= $biz ?> in writing in the event Customer disputes any portion of any fees within 60 days of the applicable charge.</li>
                        <li>If Customer fails to timely pay any amounts due, <?= $biz ?> will be entitled to suspend the Services without prior notice.</li>
                    </ol>

                    <h2>5. Termination</h2>
                    <ol>
                        <li>The term of this Agreement will commence on the Effective Date and continue for an Initial Service Term of 30 days. This Agreement will automatically renew for additional terms unless either party provides notice of non-renewal no less than 15 days prior to the end of a renewal term.</li>
                        <li>Either party may terminate this Agreement for any reason upon 30 days written notice to the other party. Either party may also terminate this Agreement in the event the other party commits any material breach and fails to remedy such breach within 5 days after written notice.</li>
                        <li>Upon termination or expiration of this Agreement, all rights and licenses granted to Customer shall immediately terminate.</li>
                    </ol>

                    <h2>6. Warranty and Disclaimer</h2>
                    <p><?= $biz ?> shall use reasonable efforts consistent with prevailing industry standards to maintain the Services in a manner which minimizes errors and interruptions. Services may be temporarily unavailable for scheduled or unscheduled maintenance, or because of causes beyond <?= $biz ?>'s reasonable control.</p>
                    <p style="text-transform: uppercase; font-size: 0.85rem;"><strong><?= strtoupper($biz) ?> HEREBY DISCLAIMS ANY AND ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING, BUT NOT LIMITED TO WARRANTIES OF MERCHANTABILITY, TITLE, NON-INFRINGEMENT, AND FITNESS FOR A PARTICULAR PURPOSE. THE SERVICES ARE PROVIDED "AS IS" TO THE FULLEST EXTENT PERMITTED BY LAW.</strong></p>

                    <h2>7. Limitation of Liability</h2>
                    <p style="text-transform: uppercase; font-size: 0.85rem;"><strong>UNDER NO CIRCUMSTANCES WILL <?= strtoupper($biz) ?> BE LIABLE FOR ANY INDIRECT, SPECIAL, INCIDENTAL, CONSEQUENTIAL OR PUNITIVE DAMAGES. <?= strtoupper($biz) ?> WILL NOT BE LIABLE FOR ANY DIRECT DAMAGES IN EXCESS OF THE AMOUNTS PAID BY CUSTOMER DURING THE SIX MONTHS PRECEDING THE INCIDENT OR CLAIM.</strong></p>

                    <h2>8. Governing Law; Disputes</h2>
                    <p>This Agreement will be governed by the laws of the State of <?= $parent['state'] ?>, exclusive of its rules governing conflicts of laws. The parties agree to the exclusive jurisdiction of the state and federal courts in the City and County of <?= $parent['city'] ?>, <?= $parent['state'] ?>.</p>

                    <h2>9. General</h2>
                    <p>Customer will not assign or otherwise transfer this Agreement without <?= $biz ?>'s prior written consent. <?= $biz ?> may assign this Agreement in whole or in part. No modification to this Agreement will be effective unless consented to in a writing signed by both parties. Each party is an independent contractor. This Agreement supersedes all prior and contemporaneous proposals, statements and agreements and contains the entire understanding of the parties. A party is not liable under this Agreement for non-performance caused by events beyond that party's control. Either party may terminate this Agreement on written notice if such event continues more than 30 days.</p>

                    <h2>Contact Information</h2>
                    <p>Please feel free to contact <?= $biz ?> if you have any questions about these Terms of Service. You may contact us at <?= $parent['phone'] ?> or at our mailing address:</p>
                    <p>
                        <?= $parent['name'] ?><br>
                        Mooresville, North Carolina<br>
                        28115, USA
                    </p>

                </div>
            </div>
        </div>
    </div>
</section>
