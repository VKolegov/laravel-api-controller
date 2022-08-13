<?php

namespace VKolegov\LaravelAPIController;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ModelXLSXExporter
{
    protected string $modelClassName;
    /**
     * @var \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     */
    protected $queryBuilder;
    protected int $lazyChunkSize = 1000;
    protected array $header = [
        ['ID']
    ];
    protected bool $autoWidth = false;
    protected array $columnDataTypes = [];
    /**
     * @var callable|null
     */
    protected $mappingFunction = null;

    protected int $columnCount = 0;

    public function __construct(string $modelClassName)
    {
        $this->modelClassName = $modelClassName;
        $this->queryBuilder = $modelClassName::query();
    }

    /**
     * @param int $lazyChunkSize
     * @return self
     */
    public function setLazyChunkSize(int $lazyChunkSize): self
    {
        $this->lazyChunkSize = $lazyChunkSize;
        return $this;
    }

    /**
     * @param array $header
     * @return self
     */
    public function setHeader(array $header): self
    {
        if (count($header) === 1 && !is_array($header[0])) {
            $header = [$header];
        }
        $maxColumnCount = 0;
        for ($i = 0; $i < count($header); $i++) {
            if (!is_array($header[$i])) {
                throw new \InvalidArgumentException('$header should be a 2D array');
            }
            $columnCount = count($header[$i]);
            if ($columnCount > $maxColumnCount) {
                $maxColumnCount = $columnCount;
            }
        }

        $this->columnCount = $maxColumnCount;

        $this->header = $header;
        return $this;
    }

    /**
     * @param callable $mappingFunction
     * @return self
     */
    public function setMappingFunction(callable $mappingFunction): self
    {
        $this->mappingFunction = $mappingFunction;
        return $this;
    }

    /**
     * @param bool $autoWidth
     * @return self
     */
    public function setAutoWidth(bool $autoWidth): self
    {
        $this->autoWidth = $autoWidth;
        return $this;
    }

    /**
     * @param array $columnDataTypes
     * @return self
     */
    public function setColumnDataTypes(array $columnDataTypes): self
    {
        $this->columnDataTypes = $columnDataTypes;
        return $this;
    }

    /**
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation $queryBuilder
     * @return self
     */
    public function setQueryBuilder($queryBuilder): self
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

    /**
     * @return array|\string[][]
     */
    public function getHeader(): array
    {
        return $this->header;
    }

    /**
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }

    public function getResponse(?string $filename = null): StreamedResponse
    {

        $spreadsheet = $this->fillOutSpreadsheet();
        $xlsx = new Xlsx($spreadsheet);

        $filename = $filename ?? $this->getFileName();

        $response = response()->streamDownload(
            function () use ($xlsx) {
                $xlsx->save(
                    "php://output"
                );
            },
            $filename
        );

        $response->headers->set('Content-Type', "application/vnd.ms-excel");
        return $response;
    }

    /**
     * @throws \Exception
     */
    public function fillOutSpreadsheet(): Spreadsheet
    {
        // fetching data
        $data = $this->rowsData();
        $dataCount = count($data);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // setting header
        $header = $this->getHeader();
        $sheet->fromArray($header);

        // header is multi-dimensional, which means we have several rows for header
        $startRow = count($header) + 1;
        $startCell = "A$startRow";


        // Заполняем данными
        $sheet->fromArray(
            $data,
            null,
            $startCell,
            true
        );
        // free memory
        $data = null;

        $this->setSheetAutoWidth($sheet);

        $this->setSheetColumnDataTypes($sheet, $startRow, $startRow + $dataCount);

        return $spreadsheet;
    }

    protected function rowsData(): array
    {
        $data = [];
        $models = $this->getLazyCollection();

        foreach ($models as $model) {
            $rowData = $this->rowData($model);

            if (!$rowData) continue;

            $data[] = $rowData;
        }
        // free memory
        $models = null;

        return $data;
    }

    protected function rowData(Model $model): ?array
    {
        if (!$this->mappingFunction) {
            return $model->toArray();
        }

        $mapped = ($this->mappingFunction)($model);
        if (empty($mapped)) {
            return null;
        }

        return $mapped;
    }

    private function setSheetAutoWidth(Worksheet $sheet)
    {
        // column auto width
        if ($this->autoWidth) {
            for ($i = 1; $i <= $this->columnCount; $i++) {
                $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function setSheetColumnDataTypes(Worksheet $sheet, int $startRow, int $endRow)
    {
        // Проходимся по документу и выставляем тип данных
        foreach ($this->columnDataTypes as $columnDataType) {

            $columnIndex = $columnDataType['index'];
            $columnType = $columnDataType['type'];

            for ($row = $startRow; $row <= $endRow; $row++) {

                $cell = $sheet->getCellByColumnAndRow($columnIndex, $row);

                switch ($columnType) {
                    case "string":
                        $cell->setDataType(DataType::TYPE_STRING);
                        break;
                    case "date":
                        $cell->getStyle()
                            ->getNumberFormat()
                            ->setFormatCode('dd.mm.yyyy');
                        break;
                    default:
                        throw new \Exception("Unknown column data type: $columnType");
                }
            }
        }
    }

    /**
     * @return \Illuminate\Support\LazyCollection|\Illuminate\Database\Eloquent\Model[]
     */
    public function getLazyCollection(): LazyCollection
    {
        return $this->getQueryBuilder()->lazy(
            $this->lazyChunkSize
        );
    }

    public function getFileName(): string
    {
        $className = class_basename($this->modelClassName);
        $today = now()->format('Y-m-d_H-i-s');
        return "{$className}_export_$today.xlsx";
    }
}