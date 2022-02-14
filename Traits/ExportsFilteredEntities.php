<?php

namespace VKolegov\LaravelAPIController\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SplFixedArray;
use Symfony\Component\HttpFoundation\StreamedResponse;
use VKolegov\LaravelAPIController\Requests\APIEntitiesRequest;

trait ExportsFilteredEntities
{

    /**
     * indices
     * @var int[]
     */
    protected array $excludedFields = [];
    protected APIEntitiesRequest $exportRequest;

    /**
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    protected function exportToXLSX(Builder $q): StreamedResponse
    {
        $maxColumns = 0;

        $this->excludedFields = $this->getExportExcludedFields();

        $data = $this->getExportData($q, $entities);

        if (count($data) === 0) {
            throw ValidationException::withMessages(
                [
                    'filter' => 'No data found'
                ]
            );
        }

        $header = $this->getExportHeader($entities);

        $spreadsheet = new Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray(
            $header,
        );

        $startCell = "A2";

        if (is_array($header[0])) {
            // multi-dimensional, which means we have several rows for header
            $startCell = "A" . (count($header) + 1);

            foreach ($header as $headerRow) {
                if (count($headerRow) > $maxColumns) {
                    $maxColumns = count($headerRow);
                }
            }
        }


        // Заполняем данными
        $sheet->fromArray(
            $data,
            null,
            $startCell,
            true
        );

        unset($data, $header);

        // Выставляем автоширину столбцов
        for ($i = 1; $i <= $maxColumns; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $response = response()->streamDownload(
            function () use ($spreadsheet) {

                $writer = new Xlsx($spreadsheet);
                $writer->save(
                    "php://output"
                );
            },
            $this->getExportFilename($entities)
        );

        $response->headers->set('Content-Type', "application/vnd.ms-excel");
        return $response;
    }

    protected function getExportExcludedFields(): array
    {
        return [];
    }

    protected function getExportHeader(LazyCollection $entities): array
    {
        return [];
    }

    protected function getExportData(Builder        $q,
                                     LazyCollection &$entities = null,
                                     int            $lazyChunkSize = 1000): array
    {
        $count = $q->count();

        $data = new SplFixedArray($count);

        $entities = $q->lazy($lazyChunkSize);
        /**
         * @var Model $entity
         */
        foreach ($entities as $i => $entity) {
            $data[$i] = $this->getExportEntityRow($entity);
        }

        return $data->toArray();
    }

    protected function getExportEntityRow(Model $entity): array
    {
        return $entity->toArray();
    }

    protected function getExportFilename(?LazyCollection $entities = null, string $extension = 'xlsx'): string
    {
        $model = Str::plural(
            Str::snake(class_basename(static::MODEL_CLASS))
        );

        return "$model.$extension";
    }
}