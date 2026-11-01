<?php

namespace Database\Seeders;

use App\Enums\AuctionStatus;
use App\Jobs\CloseAuctionJob;
use App\Jobs\StartAuctionJob;
use App\Models\Auction;
use App\Models\Category;
use App\Models\User;
use App\Services\AuctionImageService;
use App\Services\AuctionLifecycleService;
use App\Services\Bidding\BidService;
use App\Services\Bidding\IncrementCalculator;
use App\Services\Lifecycle\AuctionCloser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * A realistic catalogue: live auctions with bidding under way, upcoming ones, a draft,
 * finished results and a cancellation, each with real photos (public domain / CC0,
 * see database/seeders/photos/CREDITS.md).
 *
 * Everything goes through the real services with the clock moved back in time, so
 * prices, leaders, bid history, extensions, results and the audit log are exactly
 * what the platform itself would have produced. Skipped when auctions already exist.
 */
class DemoAuctionSeeder extends Seeder
{
    /** How many auctions the catalogue holds (used by tests). */
    public const COUNT = 16;

    private User $admin;

    /** @var array<int, User> bidder1 … bidder5 */
    private array $bidders = [];

    private CarbonImmutable $now;

    public function __construct(
        private readonly AuctionLifecycleService $lifecycle,
        private readonly AuctionImageService $images,
        private readonly BidService $bids,
        private readonly AuctionCloser $closer,
        private readonly IncrementCalculator $increments,
    ) {}

    public function run(): void
    {
        if (Auction::exists()) {
            return;
        }

        $this->admin = User::where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
        foreach (range(1, 5) as $i) {
            $this->bidders[$i] = User::where('email', "bidder{$i}@auction.test")->firstOrFail();
        }
        $this->now = CarbonImmutable::now()->startOfMinute();

        // Past events must not reach live clients, and their timed jobs are replaced below.
        $queue = Queue::getFacadeRoot();
        $broadcasting = config('broadcasting.default');
        Queue::fake();
        config(['broadcasting.default' => 'null']);

        try {
            $this->live();
            $this->upcoming();
            $this->finished();
        } finally {
            Carbon::setTestNow();
            Queue::swap($queue);
            config(['broadcasting.default' => $broadcasting]);
        }

        // The real start and close jobs, for the auctions still to start or end.
        Auction::where('status', AuctionStatus::Live)->get()->each(fn (Auction $a) => CloseAuctionJob::scheduleFor($a));
        Auction::where('status', AuctionStatus::Scheduled)->get()->each(fn (Auction $a) => StartAuctionJob::scheduleFor($a));
    }

    private function live(): void
    {
        $telescope = $this->auction('Antiques', 'Antique Brass Telescope', 'telescope', 3,
            "Three-draw brass spyglass with its original lens caps, late 19th century. Extends to about 75 cm; optics clear, with light wear to the brass consistent with age.\n\nA handsome display piece that still works.",
            ['starting_price' => 15_000, 'reserve_price' => 30_000, 'min_increment' => 500], start: '-1 day', end: '+3 hours');
        $this->bid($telescope, 2, '-20 hours');
        $this->bid($telescope, 4, '-16 hours', 1_000);
        $this->bid($telescope, 2, '-9 hours');
        $this->bid($telescope, 1, '-2 hours', 2_000);
        $this->bid($telescope, 4, '-40 minutes');

        $camera = $this->auction('Collectibles', 'Leica M6 Rangefinder Camera', 'leica-camera', 1,
            "Classic 35 mm rangefinder in black chrome with a 50 mm Summilux lens. Meter works, shutter accurate at all speeds, light brassing on the top plate.\n\nComes with strap and both caps.",
            ['starting_price' => 120_000, 'reserve_price' => 180_000, 'min_increment' => 2_500], start: '-2 days', end: '+35 minutes');
        $this->autoBid($camera, 3, 175_000, '-46 hours');
        $this->bid($camera, 5, '-30 hours');
        $this->bid($camera, 1, '-12 hours', 10_000);
        $this->bid($camera, 5, '-3 hours', 5_000);

        $raksha = $this->auction('Art', 'Hand-Carved Naga Raksha Mask', 'raksha-mask', 1,
            "Traditional Sri Lankan Naga Raksha (cobra demon) mask, carved from kaduru wood and hand-painted in natural pigments. Made by a master carver in Ambalangoda.\n\nAbout 45 cm tall, ready to hang.",
            ['starting_price' => 8_000, 'reserve_price' => null, 'min_increment' => 100], start: '-6 hours', end: '+2 days');
        $this->bid($raksha, 1, '-5 hours');
        $this->bid($raksha, 3, '-4 hours', 400);
        $this->bid($raksha, 1, '-1 hour', 900);

        $watch = $this->auction('Jewellery & watches', 'Chronograph Wristwatch with Leather Strap', 'chronograph-watch', 2,
            "Steel-cased chronograph with a white dial, sub-dials and a date window. Recently serviced; keeps good time. Black leather strap with deployant clasp.\n\nBox and service papers included.",
            ['starting_price' => 35_000, 'reserve_price' => 50_000, 'min_increment' => 500], start: '-3 hours', end: '+1 day');
        $this->watch($watch, 1);
        $this->watch($watch, 2);

        $brooch = $this->auction('Jewellery & watches', 'Victorian Diamond and Pearl Brooch', 'pearl-brooch', 1,
            "Floral spray brooch set with old-cut diamonds and natural pearls in silver over gold, circa 1880. Secure pin and C-clasp.\n\nAbout 7 cm wide.",
            ['starting_price' => 95_000, 'reserve_price' => 150_000, 'min_increment' => 1_000], start: '-1 day', end: '+6 hours');
        $this->bid($brooch, 4, '-22 hours');
        $this->bid($brooch, 2, '-6 hours', 5_000);

        $ball = $this->auction('Sports memorabilia', 'Match-Used Test Cricket Ball', 'cricket-ball', 1,
            "Red leather Test match ball, used in a home series at the R. Premadasa Stadium. Mounted on a stand with a letter of provenance from the groundsman.",
            ['starting_price' => 12_000, 'reserve_price' => 20_000, 'min_increment' => 500], start: '-10 hours', end: '+1 day');
        $this->bid($ball, 5, '-9 hours');
        $this->bid($ball, 3, '-2 hours', 1_500);
        $this->watch($ball, 1);
    }

    private function upcoming(): void
    {
        $this->auction('Art', 'Pair of Kolam Dance Masks', 'kolam-masks', 2,
            "Two painted wooden masks from the southern Kolam folk-drama tradition: a royal figure with crown and a village character. Early 20th century, with honest wear to the paint.\n\nSold as a pair.",
            ['starting_price' => 18_000, 'reserve_price' => 25_000, 'min_increment' => 500], start: '+2 hours', end: '+2 days', scheduled: true);

        $enamel = $this->auction('Jewellery & watches', 'Floral Enamel Pocket Watch', 'enamel-pocket-watch', 1,
            "French-style pocket watch with a painted enamel case and dial of flowers in pink and green. A collector's piece rather than an everyday watch: the movement needs a service.",
            ['starting_price' => 60_000, 'reserve_price' => 90_000, 'min_increment' => 1_000], start: '+1 day', end: '+3 days', scheduled: true);
        $this->watch($enamel, 2);

        $this->auction('Collectibles', 'Antique Horn Gramophone', 'gramophone', 1,
            "Wind-up gramophone in an oak cabinet with a large brass horn. Plays well at 78 rpm; spring and governor recently cleaned.\n\nIncludes ten shellac records.",
            ['starting_price' => 45_000, 'reserve_price' => 60_000, 'min_increment' => 500], start: '+3 days', end: '+5 days', scheduled: true);

        $this->auction('Art', 'Italian Landscape with Umbrella Pines', 'umbrella-pines-painting', 1,
            "Fine-art giclée reproduction on canvas after Hendrik Voogd's 1807 painting, framed in gilded wood. 90 × 65 cm.",
            ['starting_price' => 25_000, 'reserve_price' => null, 'min_increment' => 500], start: '+5 days', end: '+7 days', scheduled: true);

        // A draft: details still being finalised, not visible to bidders.
        $this->auction('Home & furniture', 'Chippendale-Style Mahogany Armchair', 'mahogany-armchair', 1,
            "Carved mahogany armchair with pierced splat, scrolled arms and ball-and-claw feet. Reupholstery recommended.",
            ['starting_price' => 70_000, 'reserve_price' => 95_000, 'min_increment' => 1_000], start: '+8 days', end: '+10 days');
    }

    private function finished(): void
    {
        $pocket = $this->auction('Jewellery & watches', 'Gold Pocket Watch with Albert Chain', 'gold-pocket-watch', 2,
            "Open-face pocket watch with white enamel dial and Roman numerals, in a gold-plated case, with matching curb-link Albert chain. Running and keeping time.",
            ['starting_price' => 40_000, 'reserve_price' => 55_000, 'min_increment' => 500], start: '-6 days', end: '-4 days');
        $this->bid($pocket, 1, '-5 days -20 hours');
        $this->bid($pocket, 3, '-5 days -2 hours', 4_000);
        $this->bid($pocket, 1, '-4 days -9 hours', 5_000);
        $this->bid($pocket, 3, '-4 days -3 hours', 6_500);
        // A bid in the final minutes extends the auction (anti-sniping).
        $this->bid($pocket, 1, '-4 days -1 minute', 1_000);
        $this->close($pocket);

        $coffer = $this->auction('Antiques', 'Carved Oak Coffer', 'oak-coffer', 1,
            "Large oak coffer with carved figurative panels and an iron lock plate, in the 16th-century Flemish style. Lid hinges replaced; interior clean.",
            ['starting_price' => 150_000, 'reserve_price' => 155_000, 'min_increment' => 2_500], start: '-9 days', end: '-7 days');
        $this->bid($coffer, 2, '-8 days -12 hours');
        $this->bid($coffer, 5, '-7 days -6 hours', 5_000);
        $this->close($coffer);

        $typewriter = $this->auction('Collectibles', 'Underwood Standard Typewriter', 'underwood-typewriter', 1,
            "Black Underwood desk typewriter from the 1930s with glass-topped keys. Types cleanly; new ribbon fitted.",
            ['starting_price' => 20_000, 'reserve_price' => 40_000, 'min_increment' => 500], start: '-5 days', end: '-3 days');
        $this->bid($typewriter, 4, '-4 days -18 hours');
        $this->bid($typewriter, 2, '-3 days -5 hours', 2_000);
        $this->close($typewriter);

        $desk = $this->auction('Home & furniture', 'Victorian Carved Writing Desk', 'writing-desk', 1,
            "Walnut davenport-style writing desk with a fall front, pigeonholes and carved panels. Leather writing surface worn.",
            ['starting_price' => 110_000, 'reserve_price' => 140_000, 'min_increment' => 2_500], start: '-4 days', end: '-2 days');
        $this->close($desk);

        $lamp = $this->auction('Antiques', 'Brass Temple Oil Lamp', 'temple-oil-lamp', 1,
            "Cast brass oil lamp with a pierced back plate of peacocks and foliage, from a South Asian temple. Five wick spouts.",
            ['starting_price' => 9_000, 'reserve_price' => null, 'min_increment' => 100], start: '+1 day', end: '+3 days', scheduled: true);
        $this->at('-2 hours', fn () => $this->lifecycle->cancel($lamp, $this->admin, 'The consignor withdrew the item.'));
    }

    /**
     * Creates the auction a few days before it starts (as the admin would), adds its photos,
     * and schedules it; live and finished auctions are then started at their start time.
     *
     * @param  array<string, int|null>  $prices
     */
    private function auction(string $category, string $title, string $photos, int $photoCount, string $description, array $prices, string $start, string $end, bool $scheduled = false): Auction
    {
        $startAt = $this->time($start);
        $endAt = $this->time($end);
        // Compared with the seeding moment, not Carbon::now(), which the clock moves around.
        $started = $startAt->lessThanOrEqualTo($this->now);
        $draft = ! $scheduled && ! $started;
        $createdAt = $started ? $startAt->subDays(2) : $this->now->subDays(1);

        $auction = $this->at($createdAt, fn () => $this->lifecycle->create([
            'title' => $title,
            'description' => $description,
            'category_id' => Category::where('slug', Category::slugFor($category))->value('id'),
            ...$prices,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'anti_snipe_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 3,
        ], $this->admin));

        $this->at($createdAt->addMinutes(10), fn () => $this->images->add($auction, array_map(
            fn (int $n) => new UploadedFile(database_path("seeders/photos/{$photos}-{$n}.jpg"), "{$photos}-{$n}.jpg", 'image/jpeg', null, true),
            range(1, $photoCount),
        ), $this->admin));

        if (! $draft) {
            $this->at($createdAt->addMinutes(20), fn () => $this->lifecycle->schedule($auction, $this->admin));
        }
        if ($started) {
            $this->at($startAt, fn () => $this->lifecycle->start($auction->id));
        }

        return $auction->refresh();
    }

    /** A manual bid at the minimum next bid plus `$extra`, placed at the given moment. */
    private function bid(Auction $auction, int $bidder, string $when, int $extra = 0): void
    {
        $this->at($this->time($when), function () use ($auction, $bidder, $extra) {
            $amount = $this->increments->minNextBid($auction->refresh()) + $extra;
            $this->bids->placeBid($auction, $this->bidders[$bidder], $amount);
        });
    }

    private function autoBid(Auction $auction, int $bidder, int $max, string $when): void
    {
        $this->at($this->time($when), fn () => $this->bids->configureAutoBid($auction, $this->bidders[$bidder], $max));
    }

    private function watch(Auction $auction, int $bidder): void
    {
        $this->bidders[$bidder]->watch($auction);
    }

    /** Runs the real closing (result, winner, reserve check) just after the auction's end, extensions included. */
    private function close(Auction $auction): void
    {
        $this->at($auction->refresh()->end_at->addMinute(), fn () => $this->closer->close($auction->id));
    }

    private function time(string $offset): CarbonImmutable
    {
        return $this->now->modify($offset);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function at(CarbonImmutable|string $moment, callable $callback): mixed
    {
        Carbon::setTestNow(is_string($moment) ? $this->time($moment) : $moment);

        return $callback();
    }
}
