<?php

namespace App\Services\Document;

use App\Models\CompanySetting;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class SerialNumberService
{
    public const VALID_PLACEHOLDERS = ['CUSTOMER_SERIES', 'SEQUENCE', 'DATE_FORMAT', 'SERIES', 'RANDOM_SEQUENCE', 'DELIMITER', 'CUSTOMER_SEQUENCE'];

    private $model;

    private $ob;

    private $customer;

    private $company;

    /**
     * @var string
     */
    public $nextSequenceNumber;

    /**
     * @var string
     */
    public $nextCustomerSequenceNumber;

    public ?int $seriesNumber = null;

    public ?int $numberYear = null;

    private bool $existingDocument = false;

    /**
     * @return $this
     */
    public function setModel($model)
    {
        $this->model = $model;

        return $this;
    }

    public function setModelObject($id = null)
    {
        $this->ob = $this->model::find($id);

        if ($this->ob) {
            $this->existingDocument = true;
            $this->seriesNumber = $this->ob->series_number ?? null;
            $this->numberYear = $this->ob->number_year ?? null;
        }

        if ($this->ob && $this->ob->sequence_number) {
            $this->nextSequenceNumber = $this->ob->sequence_number;
        }

        if (isset($this->ob) && isset($this->ob->customer_sequence_number) && isset($this->customer) && $this->ob->customer_id == $this->customer->id) {
            $this->nextCustomerSequenceNumber = $this->ob->customer_sequence_number;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function setCompany($company)
    {
        $this->company = $company;

        return $this;
    }

    /**
     * @return $this
     */
    public function setCustomer($customer = null)
    {
        $this->customer = Customer::find($customer);

        return $this;
    }

    /**
     * @return string
     */
    public function getNextNumber($data = null)
    {
        if ($this->seriesActive()) {
            if ($this->seriesNumber === null) {
                $this->previewSeriesNumber();
            }

            return NumberSeries::format($this->seriesPrefix(), $this->seriesType(), $this->numberYear, $this->seriesNumber);
        }

        $modelName = strtolower(class_basename($this->model));
        $settingKey = $modelName.'_number_format';
        $companyId = $this->company;

        if (request()->has('format')) {
            $format = request()->get('format');
        } else {
            $format = CompanySetting::getSetting(
                $settingKey,
                $companyId
            );
        }
        $this->setNextNumbers();

        $serialNumber = $this->generateSerialNumber(
            $format
        );

        return $serialNumber;
    }

    /**
     * Like setNextNumbers(), but takes the company sequence from a locked counter row so two
     * concurrent requests can never receive the same number. Call it inside the transaction that
     * saves the document: the lock is held until commit and a rollback returns the number.
     */
    public function reserveNextNumbers()
    {
        if (! $this->nextSequenceNumber) {
            // a document without a company has no counter row to lock
            $this->company ? $this->reserveSequenceNumber() : $this->setNextSequenceNumber();
        }

        if (! $this->nextCustomerSequenceNumber) {
            $this->setNextCustomerSequenceNumber();
        }

        if ($this->seriesActive() && $this->seriesNumber === null && ! $this->existingDocument) {
            $this->numberYear = NumberSeries::currentYear();
            $this->seriesNumber = NumberSeries::reserve($this->company, $this->seriesType(), $this->numberYear);
        }

        return $this;
    }

    /**
     * Whether this company numbers the model's type as <PREFIX>-<TYPE>-YYYY-NNNNNN.
     * A format typed into the form (preview of a custom format) bypasses the series.
     */
    public function seriesActive(): bool
    {
        return $this->company
            && $this->seriesType() !== null
            && ! request()->has('format')
            && $this->seriesPrefix() !== null;
    }

    private function seriesType(): ?string
    {
        return NumberSeries::TYPES[strtolower(class_basename($this->model))] ?? null;
    }

    private function seriesPrefix(): ?string
    {
        return NumberSeries::prefix($this->company);
    }

    private function previewSeriesNumber(): void
    {
        $this->numberYear ??= NumberSeries::currentYear();
        $this->seriesNumber = NumberSeries::peek($this->company, $this->seriesType(), $this->numberYear);
    }

    /**
     * Copy every reserved number onto the document being issued.
     */
    public function applyTo($document): void
    {
        $document->sequence_number = $this->nextSequenceNumber;
        $document->customer_sequence_number = $this->nextCustomerSequenceNumber;

        if ($this->seriesActive() && ! $this->existingDocument) {
            $document->series_number = $this->seriesNumber;
            $document->number_year = $this->numberYear;
        }
    }

    private function reserveSequenceNumber(): void
    {
        $type = strtolower(class_basename($this->model));
        $where = ['company_id' => $this->company, 'document_type' => $type];

        DB::transaction(function () use ($where) {
            $counter = DB::table('document_counters')->where($where)->lockForUpdate()->first();

            if (! $counter) {
                DB::table('document_counters')->insertOrIgnore($where + [
                    'last_sequence' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $counter = DB::table('document_counters')->where($where)->lockForUpdate()->first();
            }

            $highest = (int) $this->model::where('company_id', $this->company)->max('sequence_number');
            $next = max((int) $counter->last_sequence, $highest) + 1;

            DB::table('document_counters')->where($where)->update([
                'last_sequence' => $next,
                'updated_at' => now(),
            ]);

            $this->nextSequenceNumber = $next;
        });
    }

    public function setNextNumbers()
    {
        $this->nextSequenceNumber ?
            $this->nextSequenceNumber : $this->setNextSequenceNumber();

        $this->nextCustomerSequenceNumber ?
            $this->nextCustomerSequenceNumber : $this->setNextCustomerSequenceNumber();

        return $this;
    }

    /**
     * @return $this
     */
    public function setNextSequenceNumber()
    {
        $companyId = $this->company;

        $last = $this->model::orderBy('sequence_number', 'desc')
            ->where('company_id', $companyId)
            ->where('sequence_number', '<>', null)
            ->take(1)
            ->first();

        $this->nextSequenceNumber = ($last) ? $last->sequence_number + 1 : 1;

        return $this;
    }

    /**
     * @return self
     */
    public function setNextCustomerSequenceNumber()
    {
        $customer_id = ($this->customer) ? $this->customer->id : 1;

        $last = $this->model::orderBy('customer_sequence_number', 'desc')
            ->where('company_id', $this->company)
            ->where('customer_id', $customer_id)
            ->where('customer_sequence_number', '<>', null)
            ->take(1)
            ->first();

        $this->nextCustomerSequenceNumber = ($last) ? $last->customer_sequence_number + 1 : 1;

        return $this;
    }

    public static function getPlaceholders(string $format)
    {
        $regex = '/{{([A-Z_]{1,})(?::)?([a-zA-Z0-9_]{1,6}|.{1})?}}/';

        preg_match_all($regex, $format, $placeholders);
        array_shift($placeholders);
        $validPlaceholders = collect();

        /** @var array */
        $mappedPlaceholders = array_map(
            null,
            current($placeholders),
            end($placeholders)
        );

        foreach ($mappedPlaceholders as $placeholder) {
            $name = current($placeholder);
            $value = end($placeholder);

            if (in_array($name, self::VALID_PLACEHOLDERS)) {
                $validPlaceholders->push([
                    'name' => $name,
                    'value' => $value,
                ]);
            }
        }

        return $validPlaceholders;
    }

    /**
     * @return string
     */
    private function generateSerialNumber(string $format)
    {
        $serialNumber = '';

        $placeholders = self::getPlaceholders($format);

        foreach ($placeholders as $placeholder) {
            $name = $placeholder['name'];
            $value = $placeholder['value'];

            switch ($name) {
                case 'SEQUENCE':
                    $value = $value ? $value : 6;
                    $serialNumber .= str_pad($this->nextSequenceNumber, $value, 0, STR_PAD_LEFT);

                    break;
                case 'DATE_FORMAT':
                    $value = $value ? $value : 'Y';
                    $serialNumber .= date($value);

                    break;
                case 'RANDOM_SEQUENCE':
                    $value = $value ? $value : 6;
                    $serialNumber .= substr(bin2hex(random_bytes($value)), 0, $value);

                    break;
                case 'CUSTOMER_SERIES':
                    if (isset($this->customer)) {
                        $serialNumber .= $this->customer->prefix ?? 'CST';
                    } else {
                        $serialNumber .= 'CST';
                    }

                    break;
                case 'CUSTOMER_SEQUENCE':
                    $serialNumber .= str_pad($this->nextCustomerSequenceNumber, $value, 0, STR_PAD_LEFT);

                    break;
                default:
                    $serialNumber .= $value;
            }
        }

        return $serialNumber;
    }
}
