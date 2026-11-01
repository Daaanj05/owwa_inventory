# LibreOffice PDF exports

These PDFs fill an official spreadsheet, then convert it with LibreOffice (`soffice`) through `OwwaTemplateExportService::spreadsheetToPdfBinary()`. Excel downloads do not call LibreOffice.

## In the UI

- Transfer PDF and Print Preview
  - Appendix 76 PTR
  - `reports/owwa/transfer/{id}?format=pdf`
- Disposal PDF
  - Appendix 65 WMR, Appendix 74 IIRUP, or Annex A.10 IIRUSP
  - `reports/owwa/disposal/{id}?format=pdf`
- Incident report PDF and Print Preview
  - Appendix 75 RLSDDP
  - `reports/owwa/disposal/{id}?form=rlsddp&format=pdf`
- Requisition PDF
  - Appendix 63 RIS
  - `reports/owwa/requisition/{id}?format=pdf`
- RIS bulk PDF
  - One RIS PDF, or a page of per-RIS PDF links
  - `reports/owwa/bulk/requisitions?format=pdf`
- Disposal date-range report PDF
  - One PDF, or a merged workbook converted to PDF
  - `reports/owwa/bulk/disposals/report?format=pdf`
- Property cards (PDF)
  - Appendix 69, including the background job when the selection is large
  - `reports/owwa/bulk/stock-cards?format=pdf`
- Annex A.1 (PDF)
  - Semi-expendable property cards, including the background job
  - `reports/owwa/bulk/stock-cards?format=pdf`
- Annex A.4 (PDF)
  - Registry of semi-expendable property issued
  - `reports/owwa/bulk/annex-a4?format=pdf`
- API stock-card PDF
  - Queued PDF for any category when `format` is `pdf`
  - `StockCardExportController` → `GenerateStockCardExportJob`

Consumable RSMI PDF, semi-expendable ICS PDF/Excel, and PPE PAR PDF/Excel are coded Fast exports. Those buttons do not use LibreOffice.

## Acquisition PDF

There is no acquisition PDF button in the UI.

`AcquisitionViewActions::exportPdfAction()` would download a receipt line as PDF:

- consumables → Appendix 58 Stock Card
- PPE → Appendix 69 Property Card
- semi-expendable → Annex A.1

It calls `reports/owwa/acquisition/{id}?format=pdf` (`OwwaExportController::acquisition()` → `downloadAcquisitionPdf()`). No page or table registers `AcquisitionViewActions`, so that action never appears. The route still exists and still converts through LibreOffice if opened directly.

The buttons on purchase requests, purchase orders, and inspection reports are Fast PDFs (DomPDF), not this route.

Stock Levels already exports the same cards as PDF for PPE (Property cards) and semi-expendable items (Annex A.1). Those buttons are in the list above.

## Still in code, no button

`downloadPhysicalCountPdf()` and `downloadRpcspPhysicalCountSpreadsheetPdf()` still convert through LibreOffice. The physical-count PDF buttons for RPCI, RPCPPE, and RPCSP go to the Fast PDF routes instead.
