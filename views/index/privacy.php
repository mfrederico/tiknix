<?php
$biz = 'ShipCannon';
$parent = [
    'name' => 'ClickSimple INC',
    'phone' => '(866) 845-7447',
    'contact' => 'hello@clicksimple.com',
    'state' => 'North Carolina',
    'city' => 'Charlotte',
    'privacyemail' => 'privacy@clicksimple.com',
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
    .legal-content h2:first-of-type {
        margin-top: 0;
    }
    .legal-content p, .legal-content li {
        color: var(--text-secondary);
        line-height: 1.8;
        margin-bottom: 1rem;
    }
    .legal-content ul, .legal-content ol {
        padding-left: 1.5rem;
    }
    .legal-content strong {
        color: var(--text-primary);
    }
</style>

<section class="legal-hero">
    <div class="container">
        <div class="text-center">
            <h1 style="font-size: 2.5rem; font-weight: 800; color: #fff; margin-bottom: 1rem;">Privacy Policy</h1>
            <p style="color: var(--text-secondary); font-size: 1.1rem;">Last updated: March 18, 2022</p>
        </div>
    </div>
</section>

<section class="section-dark" style="padding-top: 0;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="legal-content">

                    <h2>Applicability of this Privacy Policy</h2>
                    <p><?=$parent['name']?> (d/b/a <?=$biz?>) (<?=$biz?>, "we", "our" or "us") offers a software platform and suite of services intended to help our Customers run their businesses more efficiently and effectively. We collect data about these businesses and their customers and end users ("Data") when they use the platform, the services, and our websites. This privacy policy (the "Privacy Policy") describes how we collect, use and disclose Data.</p>
                    <p>This Privacy Policy applies to <?=$biz?>'s online service tools and platform, including, without limitation, the associated <?=$biz?> mobile and desktop applications (collectively, the "Services"), <?=$biz?>.com and other <?=$parent['name']?> websites (collectively, the "Websites") and other interactions (e.g., customer service inquiries, etc.) you may have with <?=$biz?>.</p>
                    <p>If you disagree with the practices or terms described in this policy, you should (a) take the necessary steps to remove cookies from your computer after leaving our website, and (b) discontinue your use of or access to our Services, Websites, or any other aspect of <?=$biz?>'s business.</p>
                    <p>This Privacy Policy does not apply to any third-party applications or software that integrate with the Services through the <?=$biz?> platform ("Third Party Services"), or any other third-party products, services or businesses.</p>

                    <h2>Applicable Law</h2>
                    <p>We comply with relevant privacy laws, including the European Union's General Data Protection Regulation ("GDPR") and the California Consumer Privacy Act ("CCPA").</p>

                    <h2>Types and Categories of Collected Data</h2>
                    <ol>
                        <li><strong>Personal Data.</strong> Data that identifies, or that could reasonably be used to identify, an End User as an individual, or our Customer as an individual, is considered "Personal Data". We collect Personal Data when an End User registers for a <?=$biz?> account, and when a Customer sends us data. The Personal Data we collect includes contact details such as name, email address, phone number, and address. We only collect Personal Data that is relevant to providing and improving our Services.</li>
                        <li><strong>Other Data.</strong> Data other than Personal Data is considered "Other Data". Other Data includes, for example, System Logs, browser and device data, transaction data, Cookie and tracking technology data, and authorized third-party account data.</li>
                    </ol>
                    <p>Certain Data is collected automatically and, if some Data is not provided, we may be unable to provide the Services.</p>

                    <h2>How We Use and Process Collected Data</h2>
                    <p>We use and process Data to provide our Services, in accordance with Customer's instructions, including any applicable terms in the Customer Agreement.</p>
                    <ul>
                        <li><strong>Personal Data:</strong> We use Personal Data to provide the Services, contact the End User and Customer, authenticate users, handle payments, respond to inquiries, and provide customer support. <strong>We do not sell Personal Data to third parties under any circumstances.</strong></li>
                        <li><strong>Service Improvement:</strong> To provide, update, maintain and protect our Services, Websites and business.</li>
                        <li><strong>Legal Compliance:</strong> As required by applicable law, legal process or regulation.</li>
                        <li><strong>Communications:</strong> To respond to your requests, comments and questions, and to send service, technical and administrative communications.</li>
                        <li><strong>Billing:</strong> For billing, account management and other administrative matters.</li>
                        <li><strong>Security:</strong> To investigate and help prevent security issues and abuse.</li>
                    </ul>

                    <h2>How We Share and Disclose Information</h2>
                    <ol>
                        <li><strong>Data Processor.</strong> Generally, <?=$biz?> acts as the Data Processor on behalf of its Customers. When acting as a Data Processor, we use subprocessors including Amazon Web Services and Voonami, who are contractually required to adhere to protective standards.</li>
                        <li><strong>Non-Discrimination.</strong> <?=$biz?> does not discriminate between how it treats its End Users based on their exercise of rights under the CCPA.</li>
                        <li><strong>Third Party Service Providers.</strong> We may engage third-party companies or individuals as service providers to process Other Data and support our business.</li>
                        <li><strong>Legal Requirements.</strong> We may disclose Data in response to lawful requests by public authorities or as required by applicable law.</li>
                        <li><strong>Rights Protection.</strong> We may disclose Data to protect and defend the rights, property, or safety of <?=$biz?> or third parties.</li>
                        <li><strong>With Consent.</strong> <?=$biz?> may share Data with third parties when we have consent to do so.</li>
                    </ol>

                    <h2>Age Limitations</h2>
                    <p>We do not collect data from individuals under the age of eighteen (18) years old. If you are a parent or guardian and believe <?=$biz?> has collected information from anyone younger than eighteen (18) years old, please contact us so that we may verify and take steps to delete any such information.</p>

                    <h2>Place of Processing</h2>
                    <p>The Data is processed by <?=$biz?> in the United States.</p>

                    <h2>Data Retention</h2>
                    <p>The Data is kept by <?=$biz?> for the longer of the time necessary to provide the service requested by the Customer, as stated by the purposes outlined in this document, and the time required by <?=$biz?>'s contractual obligations with shipping and fulfillment providers.</p>
                    <p><?=$biz?> adheres to Amazon Data Protection Policy guidelines and requirements. After 30 days we will obfuscate any personally identifiable information on orders that originated from an Amazon MWS account.</p>

                    <h2>Data Security</h2>
                    <p>We use reasonable, proportionate, and appropriate physical, electronic, and administrative safeguards designed to protect Personal Data from loss, misuse and unauthorized access, disclosure, alteration and destruction. <?=$biz?> provides periodic training for its employees involved in the collection, protection and dissemination of Data.</p>

                    <h2>The Rights of End Users</h2>
                    <p>End Users have the right, at any time, to know whether their Personal Data has been stored and can consult the Data Controller to learn about their contents and origin, to verify their accuracy or to ask for them to be supplemented, deleted, updated or corrected.</p>
                    <p>To opt out of our marketing activities, please send an email to <?=$parent['privacyemail']?>.</p>

                    <h2>Recourse, Enforcement and Dispute Resolution</h2>
                    <p>If you have any questions or concerns, please write to us at the address listed below. We will investigate and attempt to resolve complaints and disputes regarding use and disclosure of Personal Data.</p>

                    <h2>Changes</h2>
                    <p><?=$biz?> reserves the right to change, update, modify, alter or amend this Privacy Policy from time to time by giving notice to its Customers and End Users on this page. It is strongly recommended to check this page often, referring to the date of the last update listed at the top.</p>

                    <h2>Contact Information</h2>
                    <p>Please feel free to contact <?=$biz?> if you have any questions about this Privacy Policy. You may contact us at <?=$parent['phone']?> or at our mailing address below:</p>
                    <p>
                        <strong><?=$parent['name']?></strong><br>
                        <?=nl2br($parent['contactaddress'])?>
                    </p>
                    <p>Data Protection Officer: <a href="mailto:<?=$parent['privacyemail']?>" style="color: var(--primary-color);"><?=$parent['privacyemail']?></a></p>

                </div>
            </div>
        </div>
    </div>
</section>
