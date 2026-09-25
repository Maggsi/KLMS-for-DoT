<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\SettingsFixture;
use App\Tests\Functional\DatabaseWebTestCase;

class SettingFormTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These tests assert on 302 (redirect) responses, so disable auto-follow redirects.
        // login() (in the base class) follows its own redirect manually, so it still works.
        $this->client->followRedirects(false);
    }

    public function testSettingsFormRendersInlineFieldsForAllKeys(): void
    {
        $this->databaseTool->loadFixtures([SettingsFixture::class]);
        $this->login("admin@localhost.local");

        $crawler = $this->client->request("GET", "/admin/setting/");
        $this->assertResponseStatusCodeSame(200);

        // The form must be present
        $this->assertSelectorExists("form#settingsForm");

        // Every key from SettingsFixture must appear as an inline field
        $expectedKeys = [
            "site.organisation", "site.title", "site.subtitle", "site.about",
            "site.keywords", "link.steam", "link.discord",
            "email.register.subject", "email.register.text",
            "lan.seatmap.enabled", "lan.tourney.enabled", "lan.tourney.text",
            "lan.tourney.registration_open", "booking.enabled",
            "booking.registration_require_checkin", "booking.registration_require_ticket",
            "lan.signup.enabled", "lan.signup.price", "lan.signup.discount.price",
            "lan.signup.discount.limit", "lan.signup.payment_details",
            "lan.stats.show",
        ];
        foreach ($expectedKeys as $key) {
            $this->assertSelectorExists("form#settingsForm [name=\"" . $key . "\"]");
        }

        // Category collapse headers must be present (at least "site" and "lan")
        $this->assertSelectorExists("[data-toggle=\"collapse\"]");
    }

    public function testSettingsFormSavesAllChangedValuesInOneFlush(): void
    {
        $this->databaseTool->loadFixtures([SettingsFixture::class]);
        $this->login("admin@localhost.local");

        // Change two values and submit (select the save button to get the form node)
        $crawler = $this->client->request("GET", "/admin/setting/");
        $this->client->submit($crawler->selectButton("save")->form(), [
            "site.title" => "SSP Lan Management System",
            "site.subtitle" => "Sissi State Punks",
        ]);
        $this->assertResponseStatusCodeSame(302);
        $this->client->followRedirect();
        $this->assertResponseStatusCodeSame(200);

        // Verify in DB
        $em = $this->client->getContainer()->get("doctrine")->getManager();
        $repo = $em->getRepository(\App\Entity\Setting::class);

        $title = $repo->findOneBy(["key" => "site.title"]);
        $subtitle = $repo->findOneBy(["key" => "site.subtitle"]);

        $this->assertNotNull($title);
        $this->assertEquals("SSP Lan Management System", $title->getText());
        $this->assertNotNull($subtitle);
        $this->assertEquals("Sissi State Punks", $subtitle->getText());
    }

    public function testSettingsFormDeleteButtonRemovesKey(): void
    {
        $this->databaseTool->loadFixtures([SettingsFixture::class]);
        $this->login("admin@localhost.local");

        // Delete site.keywords via the per-row delete button
        $crawler = $this->client->request("GET", "/admin/setting/");
        $this->assertResponseStatusCodeSame(200);

        // Find the delete button for site.keywords and submit its form
        // The delete button is a submit button with name="delete[site.keywords]"
        $this->client->submit($crawler->selectButton("delete_site.keywords")->form());
        $this->assertResponseStatusCodeSame(302);

        $em = $this->client->getContainer()->get("doctrine")->getManager();
        $repo = $em->getRepository(\App\Entity\Setting::class);
        $this->assertNull($repo->findOneBy(["key" => "site.keywords"]));
    }
}
