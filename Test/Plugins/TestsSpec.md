Especificaciones para implementar los tests.

# Vales
- Los vales se gestionan como una forma de pago y al comprarlo se tratan como un anticipo. Los pagos se gestionan en el modelo RecibosCli y como codpago se registrara cada empresa su forma de pago especial para vales.
- En la tabla empresas debe existir un campo codpagovales y cada empresa debe crear un registro del modelo FormaPago que quedará relacionado con el campo codpagovales de cada empresa. No se puede asignar una forma de pago a distintas empresas, la relación debe ser uno a uno.

## Producto VALE
- Debe existir un producto con referencia y codigo de barras: VALE. Que no se compre, que se venda y que no se controle el stock.
- El producto VALE no se puede modificar ni borrar.

## Casos de uso.
- Se puede comprar un vale.
- No se puede comparar un vale sin pvpunitario.
- No se puede borrar una linea de productoo de vale si el documento ya está en estado no editable.
- Al comprar un vale, se debe crear el vale y asignar el numero de vale a la descripcion de la linea. Tambien debe quedar relacionada la linea con el vale.
- Al devolver una factura que contenga una linea de compra de vale, se debe registrar este movimiento.
- No se debe permitir devolver una factura que tenga una linea de compra de vale si este ya tiene movimientos asociados.
- Los movimientos no se pueden modificar o borrar.
- Cada vez que se añada un movimiento, se debe actualiar el saldo del vale. Si una vez actualizado el saldo es cero, se debe desactivar el vale.
- Los vales y movimientos se deben relacionar con la empresa del documento.
- Todos estos casos de uso se deben comprobar tanto creando un documento o por la clase SaleForm::apply()
- Los movimientos de vales estan relacionados 1-1 con los recibos. En los casos de los albaranes, con la tabla prepagoscli y el modelo PrePagoCli.
- Se puede pasar de AlbaranCliente a FacturaCliente y no afectar a los movimientos ni saldos de los vales. Se mantendrá la relación con la linea original, aunque se genere otro documento.
