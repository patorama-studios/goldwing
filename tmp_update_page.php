<?php
require_once __DIR__ . '/app/bootstrap.php';

$html = <<<HTML
<style>
.chapters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
}
.state-card {
    background: #1e1e1e;
    border-radius: 12px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.05);
    display: flex;
    flex-direction: column;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.state-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(212, 175, 55, 0.15);
    border-color: rgba(212, 175, 55, 0.3);
}
.state-card-header {
    padding: 2rem;
    position: relative;
    background: linear-gradient(135deg, #2a2a2a 0%, #151515 100%);
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 140px;
    border-bottom: 2px solid rgba(255,255,255,0.05);
}
.state-card-header h3 {
    margin: 0;
    color: #d4af37;
    font-size: 1.8rem;
    z-index: 2;
    text-shadow: 0 2px 4px rgba(0,0,0,0.8);
}
.state-card-header a {
    color: inherit;
    text-decoration: none;
}
.state-card-header a:hover {
    text-decoration: underline;
}
.state-image-bg {
    position: absolute;
    right: -10%;
    top: 50%;
    transform: translateY(-50%);
    width: 60%;
    height: 140%;
    opacity: 0.15;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center right;
    pointer-events: none;
    z-index: 1;
    filter: drop-shadow(0 0 10px rgba(255,255,255,0.2));
}
.state-card-body {
    padding: 1.5rem;
    flex-grow: 1;
    background: #1e1e1e;
}
.chapter-accordion {
    margin-bottom: 0.75rem;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    background: #252525;
    overflow: hidden;
}
.chapter-accordion summary {
    padding: 1rem;
    cursor: pointer;
    font-weight: 600;
    font-size: 1.1rem;
    color: #f1f1f1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    list-style: none;
    background: #2a2a2a;
    transition: background 0.2s ease;
}
.chapter-accordion summary::-webkit-details-marker {
    display: none;
}
.chapter-accordion summary:hover {
    background: #333;
}
.chapter-accordion summary::after {
    content: '+';
    font-size: 1.5rem;
    line-height: 1;
    color: #888;
    transition: transform 0.3s ease;
}
.chapter-accordion[open] summary::after {
    transform: rotate(45deg);
    color: #d4af37;
}
.chapter-accordion[open] summary {
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.chapter-details {
    padding: 1rem;
    animation: fadeInDown 0.3s ease-out;
}
.rep-name {
    font-size: 1.2rem;
    color: #fff;
    margin-bottom: 0.5rem;
}
.rep-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}
.rep-btn {
    flex: 1;
    text-align: center;
    padding: 0.6rem;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 0.4rem;
}
.rep-btn.call-btn {
    background: rgba(212, 175, 55, 0.1);
    color: #d4af37;
    border: 1px solid rgba(212, 175, 55, 0.3);
}
.rep-btn.call-btn:hover {
    background: rgba(212, 175, 55, 0.2);
}
.rep-btn.email-btn {
    background: rgba(255, 255, 255, 0.05);
    color: #ccc;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.rep-btn.email-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}
.no-chapter-notice {
    text-align: center;
    padding: 1rem;
    color: #aaa;
}
@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.intro-hero {
    text-align: left;
    padding: 3rem;
    background: url('/uploads/about/about-chapters-hero.jpg') center/cover no-repeat;
    border-radius: 12px;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}
.intro-hero::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(to right, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0.4) 100%);
}
.intro-hero-content {
    position: relative;
    z-index: 2;
    max-width: 600px;
}
.intro-hero h2 {
    color: #d4af37;
    font-size: 2.5rem;
    margin-bottom: 1rem;
    text-shadow: 0 2px 10px rgba(0,0,0,0.8);
}
.intro-hero p {
    font-size: 1.2rem;
    color: #eee;
    line-height: 1.6;
}
</style>

<div class="intro-hero">
    <div class="intro-hero-content">
        <h2>Many hands make light work</h2>
        <p>Local chapters are the heart of the association. Each state has representatives who coordinate rides and support members. Join your local chapter to meet riders, plan chapter rides, and stay connected.</p>
    </div>
</div>

<div class="chapters-grid">

    <!-- NSW -->
    <div class="state-card">
        <div class="state-card-header">
            <h3><a href="/?page=chapters-nsw">New South Wales</a></h3>
            <div class="state-image-bg" style="background-image: url('/uploads/library/2025/state-nsw.svg');"></div>
        </div>
        <div class="state-card-body">
            <!-- Central Coast -->
            <details class="chapter-accordion">
                <summary>Central Coast</summary>
                <div class="chapter-details">
                    <div class="rep-name">Mal Allen</div>
                    <div class="rep-actions">
                        <a href="tel:0455380162" class="rep-btn call-btn">📞 0455 380 162</a>
                        <a href="mailto:ar.centralcoast@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- Central West -->
            <details class="chapter-accordion">
                <summary>Central West</summary>
                <div class="chapter-details">
                    <div class="rep-name">Dorothy Springett</div>
                    <div class="rep-actions">
                        <a href="tel:0402075741" class="rep-btn call-btn">📞 0402 075 741</a>
                        <a href="mailto:ar.centralwest@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- Coffs Coast -->
            <details class="chapter-accordion">
                <summary>Coffs Coast</summary>
                <div class="chapter-details">
                    <div class="rep-name">Brian Platts</div>
                    <div class="rep-actions">
                        <a href="tel:0400409681" class="rep-btn call-btn">📞 0400 409 681</a>
                        <a href="mailto:ar.coffscoast@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- New England -->
            <details class="chapter-accordion">
                <summary>New England</summary>
                <div class="chapter-details">
                    <div class="rep-name">Allan Piddington</div>
                    <div class="rep-actions">
                        <a href="tel:0267722706" class="rep-btn call-btn">📞 02 6772 2706</a>
                        <a href="mailto:ar.newengland@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- North West -->
            <details class="chapter-accordion">
                <summary>North West</summary>
                <div class="chapter-details">
                    <div class="rep-name">Stephen (Skippy) Ward</div>
                    <div class="rep-actions">
                        <a href="tel:0267431725" class="rep-btn call-btn">📞 02 6743 1725</a>
                        <a href="mailto:ar.northwest@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- Riverina -->
            <details class="chapter-accordion">
                <summary>Riverina</summary>
                <div class="chapter-details">
                    <div class="rep-name">Kevin Lindley</div>
                    <div class="rep-actions">
                        <a href="tel:0267722706" class="rep-btn call-btn">📞 02 6772 2706</a>
                        <a href="mailto:ar.riverina@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- Sydney -->
            <details class="chapter-accordion">
                <summary>Sydney</summary>
                <div class="chapter-details">
                    <div class="rep-name">Wayne Gannon</div>
                    <div class="rep-actions">
                        <a href="tel:0449150530" class="rep-btn call-btn">📞 0449 150 530</a>
                        <a href="mailto:ar.sydney@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- QLD -->
    <div class="state-card">
        <div class="state-card-header">
            <h3><a href="/?page=chapters-qld">Queensland</a></h3>
            <div class="state-image-bg" style="background-image: url('/uploads/library/2025/state-qld.svg');"></div>
        </div>
        <div class="state-card-body">
            <!-- Brisbane -->
            <details class="chapter-accordion">
                <summary>Brisbane</summary>
                <div class="chapter-details">
                    <div class="rep-name">Greg Naylor</div>
                    <div class="rep-actions">
                        <a href="tel:0410256667" class="rep-btn call-btn">📞 0410 256 667</a>
                        <a href="mailto:ar.brisbane@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- Fraser Coast -->
            <details class="chapter-accordion">
                <summary>Fraser Coast</summary>
                <div class="chapter-details">
                    <div class="rep-name">Robert '86' Watson</div>
                    <div class="rep-actions">
                        <a href="tel:0400112012" class="rep-btn call-btn">📞 0400 112 012</a>
                        <a href="mailto:ar.frasercoast@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- WA -->
    <div class="state-card">
        <div class="state-card-header">
            <h3><a href="/?page=chapters-wa">Western Australia</a></h3>
            <div class="state-image-bg" style="background-image: url('/uploads/library/2025/state-wa.svg');"></div>
        </div>
        <div class="state-card-body">
            <!-- Perth -->
            <details class="chapter-accordion">
                <summary>Perth</summary>
                <div class="chapter-details">
                    <div class="rep-name">David Goodchild</div>
                    <div class="rep-actions">
                        <a href="tel:0417987742" class="rep-btn call-btn">📞 0417 987 742</a>
                        <a href="mailto:ar.perth@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
            <!-- West Coast Wings -->
            <details class="chapter-accordion">
                <summary>West Coast Wings</summary>
                <div class="chapter-details">
                    <div class="rep-name">Gary Cubbage</div>
                    <div class="rep-actions">
                        <a href="tel:0407447159" class="rep-btn call-btn">📞 0407 447 159</a>
                        <a href="mailto:ar.westcoastwings@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- SA -->
    <div class="state-card">
        <div class="state-card-header">
            <h3><a href="/?page=chapters-sa">South Australia</a></h3>
            <div class="state-image-bg" style="background-image: url('/uploads/library/2025/state-sa.svg');"></div>
        </div>
        <div class="state-card-body">
            <!-- South Australian Chapter -->
            <details class="chapter-accordion">
                <summary>South Australian</summary>
                <div class="chapter-details">
                    <div class="rep-name">Colin Underhill</div>
                    <div class="rep-actions">
                        <a href="tel:0421357116" class="rep-btn call-btn">📞 0421 357 116</a>
                        <a href="mailto:ar.southaustralian@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- TAS -->
    <div class="state-card">
        <div class="state-card-header">
            <h3><a href="/?page=chapters-tas">Tasmania</a></h3>
            <div class="state-image-bg" style="background-image: url('/uploads/library/2025/state-tas.svg');"></div>
        </div>
        <div class="state-card-body">
            <!-- Tasmania Chapter -->
            <details class="chapter-accordion">
                <summary>Tasmania</summary>
                <div class="chapter-details">
                    <div class="rep-name">Dennis Davis (Contact)</div>
                    <div class="rep-actions">
                        <a href="tel:0429351615" class="rep-btn call-btn">📞 0429 351 615</a>
                        <a href="mailto:ar.tasmania@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- VIC -->
    <div class="state-card">
        <div class="state-card-header">
            <h3><a href="/?page=chapters-vic">Victoria</a></h3>
            <div class="state-image-bg" style="background-image: url('/uploads/library/2025/state-vic.svg');"></div>
        </div>
        <div class="state-card-body">
            <div class="no-chapter-notice">
                <p>No local chapter yet. Contact the National President.</p>
                <div class="rep-actions">
                    <a href="tel:0429324426" class="rep-btn call-btn">📞 0429 324 426</a>
                    <a href="mailto:aga.president@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                </div>
            </div>
        </div>
    </div>

    <!-- NT -->
    <div class="state-card">
        <div class="state-card-header">
            <h3><a href="/?page=chapters-nt">Northern Territory</a></h3>
            <div class="state-image-bg" style="background-image: url('/uploads/library/2025/state-nt.svg');"></div>
        </div>
        <div class="state-card-body">
            <div class="no-chapter-notice">
                <p>No local chapter yet. Contact the National President.</p>
                <div class="rep-actions">
                    <a href="tel:0429324426" class="rep-btn call-btn">📞 0429 324 426</a>
                    <a href="mailto:aga.president@goldwing.org.au" class="rep-btn email-btn">✉️ Email</a>
                </div>
            </div>
        </div>
    </div>

</div>

<p style="text-align: center; margin-top: 2rem; color: #888;">If you are new, start by joining a chapter and introducing yourself at a ride.</p>
HTML;

$pdo = db();
$stmt = $pdo->prepare("UPDATE pages SET live_html = :html WHERE slug = 'chapters-representatives'");
$stmt->execute(['html' => $html]);

echo "Successfully updated chapters-representatives page!\n";
