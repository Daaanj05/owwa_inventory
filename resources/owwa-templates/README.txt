OWWA Excel templates – keep original file names
=================================================

You do NOT need to rename OWWA files. Both .xlsx and .xls (Excel 97–2003) are supported (e.g. "Appendix 65 - WMR.xls", "Appendix 58 - SC.xlsx"). Use the exact filename in config/owwa_templates.php.

1) Place the .xlsx files in the right folder (by category):

  storage/app/templates/
    consumables/     – all Consumables forms (issuance, transfer, disposal)
    ppe/             – all PPE forms
    semi_expendable/ – all Semi-Expendable forms

  Example: consumables/Appendix 65 - WMR.xlsx, consumables/Appendix 58 - SC.xlsx

2) Tell the app which file to use for each category and form in config/owwa_templates.php:

  - For each transaction type (issuance, transfer, disposal) and category (consumables, ppe, semi_expendable),
    you map a form key (e.g. 'default', 'sc') to the actual filename and a label for the dropdown.

  - Example for issuance + consumables:
    'default' => ['file' => 'Appendix 65 - WMR.xlsx', 'label' => 'Appendix 65 - WMR'],
    'sc'      => ['file' => 'Appendix 58 - SC.xlsx',  'label' => 'Appendix 58 - SC'],

  - The dropdown "Export OWWA form (choose…)" will show those labels. "Export OWWA form" uses the default form.

3) Optional – without config:

  Put .xlsx files in the category folder. The "choose form" dropdown will list all files in that category. Default form is {category}.xlsx (e.g. consumables.xlsx).

After placing the .xlsx files, run:

  php artisan owwa:analyze-templates

to generate template-structure.txt for mapping database columns and cell references.
