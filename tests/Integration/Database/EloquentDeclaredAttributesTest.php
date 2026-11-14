<?php

namespace Illuminate\Tests\Integration\Database;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Column;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasDeclaredAttributes;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use TypeError;

class EloquentDeclaredAttributesTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase()
    {
        Schema::create('declared_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reference');
            $table->string('status');
            $table->integer('quantity');
            $table->decimal('total', 8, 2);
            $table->boolean('paid');
            $table->json('meta')->nullable();
            $table->string('secret')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);

        parent::tearDown();
    }

    public function testDeclaredPropertiesAreBackedByTheAttributesArray()
    {
        $invoice = new DeclaredInvoice;
        $invoice->note = 'first';

        $this->assertSame('first', $invoice->note);
        $this->assertSame('first', $invoice->getAttributes()['note']);
        $this->assertSame('first', $invoice['note']);
        $this->assertTrue(isset($invoice->note));
        $this->assertFalse(isset($invoice->secret));
        $this->assertTrue($invoice->isDirty('note'));
        $this->assertArrayNotHasKey('note', get_object_vars($invoice));

        $invoice->setAttribute('note', 'second');
        $this->assertSame('second', $invoice->note);

        unset($invoice->note);
        $this->assertArrayNotHasKey('note', $invoice->getAttributes());
        $this->assertNull($invoice->note);
    }

    public function testPropertyTypesActAsImplicitCasts()
    {
        $invoice = DeclaredInvoice::create($this->attributes());

        $invoice = DeclaredInvoice::find($invoice->id);

        $this->assertSame(1, $invoice->id);
        $this->assertSame(DeclaredInvoiceStatus::Paid, $invoice->status);
        $this->assertSame(3, $invoice->quantity);
        $this->assertSame(10.5, $invoice->total);
        $this->assertTrue($invoice->paid);
        $this->assertSame(['po' => 42], $invoice->meta);
        $this->assertInstanceOf(CarbonImmutable::class, $invoice->issued_at);
        $this->assertInstanceOf(CarbonInterface::class, $invoice->due_at);
        $this->assertNotInstanceOf(CarbonImmutable::class, $invoice->due_at);
        $this->assertSame('2026-11-14', $invoice->issued_at->toDateString());

        $this->assertSame('paid', DB::table('declared_invoices')->value('status'));
    }

    public function testExplicitCastsTakePrecedenceOverImplicitCasts()
    {
        $casts = (new DeclaredInvoice)->getCasts();

        $this->assertSame('date', $casts['due_at']);
        $this->assertSame('immutable_datetime', $casts['issued_at']);
        $this->assertSame('integer', $casts['quantity']);
        $this->assertSame(DeclaredInvoiceStatus::class, $casts['status']);
        $this->assertArrayNotHasKey('reference', $casts);
    }

    public function testDirtyTrackingAndChanges()
    {
        $invoice = DeclaredInvoice::create($this->attributes());

        $this->assertFalse($invoice->isDirty());

        $invoice->quantity = '3';
        $this->assertFalse($invoice->isDirty('quantity'));

        $invoice->status = DeclaredInvoiceStatus::Draft;
        $invoice->quantity = 5;
        $this->assertTrue($invoice->isDirty(['status', 'quantity']));
        $this->assertSame(3, $invoice->getOriginal('quantity'));

        $invoice->save();

        $this->assertSame(DeclaredInvoiceStatus::Draft->value, $invoice->getChanges()['status']);
        $this->assertSame(5, $invoice->getChanges()['quantity']);
        $this->assertTrue($invoice->wasChanged(['status', 'quantity']));
        $this->assertFalse($invoice->wasChanged('reference'));
        $this->assertSame(5, DeclaredInvoice::find($invoice->id)->quantity);
    }

    public function testMutatorsAndAccessorsStillApply()
    {
        $invoice = new DeclaredInvoice(['reference' => '  inv-9 ']);

        $this->assertSame('INV-9', $invoice->reference);
        $this->assertSame('inv-9', $invoice->getAttributes()['reference']);
    }

    public function testFillCreateUpdateRespectMassAssignment()
    {
        $invoice = DeclaredInvoice::create($this->attributes() + ['secret' => 'nope']);

        $this->assertNull($invoice->secret);

        $invoice->update(['quantity' => 7, 'secret' => 'still-nope']);

        $fresh = DeclaredInvoice::find($invoice->id);
        $this->assertSame(7, $fresh->quantity);
        $this->assertNull($fresh->secret);

        $fresh->fill(['note' => 'filled']);
        $this->assertSame('filled', $fresh->note);
    }

    public function testToArrayAndToJsonRespectHiddenAttributes()
    {
        $invoice = DeclaredInvoice::create($this->attributes());
        $invoice->forceFill(['secret' => 'shh'])->save();

        $array = DeclaredInvoice::find($invoice->id)->toArray();

        $this->assertSame('INV-1', $array['reference']);
        $this->assertSame('paid', $array['status']);
        $this->assertSame(3, $array['quantity']);
        $this->assertTrue($array['paid']);
        $this->assertArrayNotHasKey('secret', $array);

        $json = json_decode($invoice->fresh()->toJson(), true);
        $this->assertSame('INV-1', $json['reference']);
        $this->assertArrayNotHasKey('secret', $json);
    }

    public function testReplicateRefreshAndClone()
    {
        $invoice = DeclaredInvoice::create($this->attributes());

        $copy = $invoice->replicate();
        $this->assertNull($copy->id);
        $this->assertSame('INV-1', $copy->reference);
        $copy->reference = 'INV-COPY';
        $copy->save();
        $this->assertSame('INV-1', $invoice->reference);

        DB::table('declared_invoices')->where('id', $invoice->id)->update(['quantity' => 99]);
        $invoice->refresh();
        $this->assertSame(99, $invoice->quantity);
        $this->assertSame(99, $invoice->fresh()->quantity);

        $clone = clone $invoice;
        $clone->quantity = 1;
        $this->assertSame(99, $invoice->quantity);
        $this->assertSame(1, $clone->quantity);
    }

    public function testSerializationAndQueueSerialization()
    {
        $invoice = DeclaredInvoice::create($this->attributes());

        $restored = unserialize(serialize($invoice));
        $this->assertSame('INV-1', $restored->reference);
        $this->assertSame(DeclaredInvoiceStatus::Paid, $restored->status);
        $restored->quantity = 4;
        $this->assertSame(4, $restored->getAttributes()['quantity']);
        $this->assertArrayNotHasKey('quantity', get_object_vars($restored));

        $job = unserialize(serialize(new DeclaredInvoiceJob($invoice)));
        $this->assertTrue($job->invoice->is($invoice));
        $this->assertSame('INV-1', $job->invoice->reference);
        $this->assertSame(3, $job->invoice->quantity);
    }

    public function testNullValuesAreCheckedAgainstTheDeclaredType()
    {
        $invoice = new DeclaredInvoice;

        $this->assertNull($invoice->note);
        $this->assertNull($invoice->id);

        $this->expectException(TypeError::class);

        $invoice->quantity;
    }

    public function testMissingAttributesStillThrowWhenPrevented()
    {
        DeclaredInvoice::create($this->attributes());

        Model::preventAccessingMissingAttributes();

        $invoice = DeclaredInvoice::select('id')->first();

        $this->assertSame(1, $invoice->id);

        $this->expectException(MissingAttributeException::class);

        $invoice->quantity;
    }

    public function testInvalidDeclarationsThrow()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DeclaredInvalidModel::$name');

        new DeclaredInvalidModel;
    }

    public function testModelsWithoutTheTraitAreUntouched()
    {
        $model = new UndeclaredInvoice(['reference' => 'INV-1']);

        $this->assertSame('INV-1', $model->getAttributes()['reference']);
        $this->assertSame('plain', $model->label);
        $this->assertArrayNotHasKey('label', $model->getAttributes());
    }

    protected function attributes(): array
    {
        return [
            'reference' => 'INV-1',
            'status' => DeclaredInvoiceStatus::Paid,
            'quantity' => 3,
            'total' => 10.5,
            'paid' => true,
            'meta' => ['po' => 42],
            'issued_at' => '2026-11-14 10:00:00',
            'due_at' => '2026-12-14',
        ];
    }
}

enum DeclaredInvoiceStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
}

class DeclaredInvoice extends Model
{
    use HasDeclaredAttributes;

    protected $table = 'declared_invoices';

    protected $fillable = ['reference', 'status', 'quantity', 'total', 'paid', 'meta', 'note', 'issued_at', 'due_at'];

    protected $hidden = ['secret'];

    #[Column] public ?int $id;
    #[Column] public string $reference;
    #[Column] public DeclaredInvoiceStatus $status;
    #[Column] public int $quantity;
    #[Column] public float $total;
    #[Column] public bool $paid;
    #[Column] public ?array $meta;
    #[Column] public ?string $secret;
    #[Column] public ?string $note;
    #[Column] public ?CarbonImmutable $issued_at;
    #[Column] public ?CarbonInterface $due_at;

    protected function casts(): array
    {
        return ['due_at' => 'date'];
    }

    protected function reference(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_null($value) ? null : strtoupper($value),
            set: fn ($value) => strtolower(trim($value)),
        );
    }
}

class DeclaredInvalidModel extends Model
{
    use HasDeclaredAttributes;

    #[Column] public string $name = 'default';
}

class UndeclaredInvoice extends Model
{
    protected $guarded = [];

    public string $label = 'plain';
}

class DeclaredInvoiceJob
{
    use SerializesModels;

    public function __construct(public DeclaredInvoice $invoice)
    {
    }
}
