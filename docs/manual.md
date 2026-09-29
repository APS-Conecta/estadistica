# Manual de Estadística

Para el personal que consulta cifras REM y sigue las Metas Sanitarias, y para quien administra la aplicación. Los
nombres entre comillas latinas («…») son los que aparecen en pantalla.

## 1. Qué muestra, y qué no

Estadística guarda las cifras REM que el DEIS publica para **todos los establecimientos del país**, mes a mes, y las
etiqueta con el diccionario oficial de cada año. Con ellas puede poner las cifras de **su establecimiento** al lado
del **promedio nacional** y del de **establecimientos parecidos** (los «pares»), y seguir el avance de las **Metas
Sanitarias de Atención Primaria** mes a mes.

- Solo guarda **conteos agregados** publicados por el DEIS y FONASA. No contiene información de pacientes.
- No llena ni valida la planilla REM, y no lee las series BS, BM ni D.
- **Su establecimiento lo define la instalación**, no usted: ninguna pantalla permite elegir otro centro como
  «el propio». Si la aplicación avisa «Esta instalación todavía no tiene su establecimiento configurado», verá las
  cifras nacionales y de pares, pero no las propias; avise a quien administra la instalación.

La aplicación tiene cuatro pestañas: **Consultar**, **Preguntas**, **Tableros** y **Metas Sanitarias**.

## 2. Consultar

Una consulta se arma en cuatro pasos, de arriba abajo, y se hace al presionar **«Consultar»**, no antes.

1. **Datos.** Elija la fuente: «REM Serie A» (mensual) o «REM Serie P» (semestral, con cortes a junio y diciembre).
   Bajo cada una se ve de quién es, qué años hay cargados y hasta qué mes llegan sus cifras.
2. **Filtrar.**
   - «Desde» y «Hasta»: los meses de la consulta.
   - «Hoja del REM» y «Sección»: por ejemplo, la hoja A03 y una de sus secciones.
   - «Prestaciones (vacío: todas las de la sección)»: déjelo vacío para sumar la sección completa.
   - «Columnas que se suman»: qué columnas de esas filas entran en la cifra.
3. **Resumir.**
   - «Agrupar por (hasta 2)»: año, mes, prestación, columna, o un rasgo del establecimiento (tipo, dependencia,
     nivel de atención, servicio de salud, región, comuna).
   - «Métrica»: «Suma» o «Promedio por establecimiento». Al comparar se usa siempre el promedio: la suma de todo el
     país no se puede poner al lado de la de un solo establecimiento.
   - «Comparar con»: «Todo el país», y los **pares**, eligiendo en cada rasgo el valor que deben compartir.
4. **Visualizar.** «Barras», «Líneas» o «Solo tabla». Todo gráfico trae su tabla debajo, con las mismas cifras.

Si la consulta no se puede hacer, un mensaje en español dice por qué, y nada se pierde: corrija el paso que indica.
Si nadie informó esas prestaciones y columnas en el periodo, verá «No hay cifras para esta consulta».

Cuando un año no trae diccionario propio, la aplicación usa las etiquetas del año posterior más cercano que sí lo
tiene, y lo avisa. Una prestación que ningún diccionario nombra aparece como «sin descripción».

## 3. Preguntas

Una consulta útil se guarda: escriba un «Título de la pregunta» bajo el resultado y presione **«Guardar como
pregunta»**. **Lo que guarde queda visible para todo el personal.**

En la pestaña **Preguntas** están todas, con su autor. «Ver» la hace de nuevo con las cifras de hoy. Quien la creó, y
quien administra Estadística, pueden «Editar» su título y su forma de mostrarse, o «Eliminar» la pregunta.

## 4. Tableros

Un tablero reúne preguntas guardadas como tarjetas. En la pestaña **Tableros**:

- escriba un «Título del nuevo tablero» y presione **«Crear tablero»**;
- ábralo, elija una pregunta en «Agregar una pregunta guardada» y presione **«Agregar tarjeta»**;
- ordene las tarjetas con «Subir» y «Bajar», cambie su «Ancho de la tarjeta» (de 1 a 3 columnas) o use «Quitar».

Cada tarjeta hace su propia consulta: si una falla, las demás siguen mostrándose. Si alguien elimina una pregunta que
el tablero muestra, su tarjeta dice «Esta pregunta fue eliminada»; quien edita el tablero puede quitarla. Eliminar
un tablero no elimina sus preguntas.

## 5. Metas Sanitarias

La pestaña muestra las Metas Sanitarias del año (Ley 19.813), **una tarjeta por meta**:

- el nombre de la meta y su indicador;
- la meta nacional, la **meta comprometida** por su establecimiento y el peso de la meta;
- de dónde sale el denominador: la población inscrita validada por FONASA a una fecha de corte, o una cifra que su
  establecimiento comprometió;
- una **tabla mes a mes**: el numerador, el denominador y el porcentaje de su establecimiento; la diferencia en
  puntos «Frente a la meta comprometida» y «Frente a la meta nacional»; y el porcentaje «Nacional» y de «Pares».

Arriba puede elegir el «Año» y los **pares**: los establecimientos que comparten con el suyo los rasgos que marque
(parte con el tipo de establecimiento). Si desmarca todos, la columna «Pares» desaparece.

Para leer la tabla:

- Las metas que se cuentan en la **Serie P** se miden en sus cortes de junio y diciembre: cada mes muestra el último
  corte hasta ese mes, y no hay cifra antes de junio.
- Un mes que el DEIS todavía no publica aparece con una raya.
- Una meta que se cuenta sobre población no muestra porcentaje mientras no esté cargada la población de FONASA, y lo
  dice.
- La **Meta VIII** no tiene cifras REM: muestra el cumplimiento que se informó a mano.
- La aplicación **no calcula un cumplimiento global**: el oficial es el de la SEREMI.
- Las cifras nacionales y de pares suman a todos los establecimientos que informaron. Es el mismo cálculo para todos,
  no la evaluación de la SEREMI.

## 6. Palabras que conviene conocer

- **Su establecimiento (local)**: el que definió la instalación.
- **Nacional**: todos los establecimientos que informaron. **Pares**: los que comparten los rasgos elegidos.
- **Informante**: un establecimiento que informó al menos una de las celdas elegidas en el periodo.
- **Promedio por establecimiento**: la suma dividida por los informantes.
- **Fecha de corte**: hasta cuándo llegan las cifras de una fuente.
- **Incompleto**: un año que el DEIS publicó cortado. Se cargaron solo sus filas enteras, y se muestra igual, con el
  motivo en la administración.

## 7. Para quien administra Estadística

Todo está en **Administración → Estadística**. Lo ven los administradores de Nextcloud y los grupos a los que se les
delegue la sección en **Administración → Privilegios de administración**.

Arriba, la sección dice qué establecimiento sirve esta instalación. **Se define en el instalador, no aquí.** Si dice
«Todavía no se ha configurado el establecimiento de esta instalación», avise a quien instaló la suite.

### Años de historia nacional

El «Primer año» de cifras nacionales que se guardan, hasta el actual. La pantalla muestra cuánto ocupará en la base
de datos y en cada respaldo. Presione **«Guardar»**: los años se cargan o se quitan en segundo plano. Si el nuevo
primer año deja fuera años ya cargados, o un ZIP que espera cargarse, la pantalla lo avisa y pide confirmar antes de
guardar.

### Cifras REM cargadas

Cada año y serie, con su origen (el DEIS o un archivo cargado a mano), estado, fecha de corte, filas, códigos «Sin
descripción» y último intento. La aplicación
revisa el DEIS una vez al día y carga una serie por hora. **Si una carga falla, sigue mostrando la anterior** y aquí
queda escrito por qué. Más abajo se ve la Base de Establecimientos en uso.

### Población inscrita validada (FONASA)

FONASA no publica su población inscrita en un archivo que se pueda descargar solo, así que se carga a mano:

- el CSV «Inscritos APS» que exporta el reporte de FONASA, por «Cargar un archivo a mano»; o
- en «Población de su establecimiento», las cifras de su establecimiento por tramo de edad y sexo, para el corte al
  31 de diciembre del año que elija, cuando FONASA todavía no las entrega o para corregirlas. Una casilla en blanco
  usa la cifra de FONASA, la que se ve en gris. Lo escrito vale solo para su establecimiento: el país y los pares
  siguen con las cifras de FONASA.

Un año sin corte propio usa el último corte anterior multiplicado por 1,03.

### Metas Sanitarias

Elija el año en «Metas de» y escriba lo que comprometió su establecimiento:

- la «Meta comprometida (%)» de cada meta;
- el «Denominador comprometido» de las metas que se cuentan sobre población, si se comprometió otro que la
  población de FONASA;
- el «Cumplimiento (%)» de las metas que se informan a mano, como la Meta VIII.

Una casilla en blanco no compromete nada. Presione **«Guardar»**.

### Cargar un archivo a mano

Para lo que la instalación no descarga sola: suba el archivo a **Archivos** y elíjalo aquí. Se revisa al momento, y
un archivo que no sea uno de estos cuatro se rechaza sin guardar nada.

- **El ZIP de cifras REM** que publica el DEIS (por ejemplo «SERIE_REM_2024.zip»): se carga en segundo plano, una
  serie por hora, igual que una descarga automática.
- **La «Base de Establecimientos»** del DEIS (.xlsx): queda en uso de inmediato.
- **El CSV «Inscritos APS»** de FONASA: queda en uso de inmediato. Un archivo con columnas de más o de menos (por
  ejemplo, un RUT o un nombre) se rechaza entero, y dice cuáles sobran y cuáles faltan.
- **Un archivo de Metas Sanitarias** (.json), como el que la aplicación trae para 2026: crea o reemplaza las metas de
  su año, y lo comprometido para ese año se mantiene. **Cada octubre**, cuando el Ministerio publica las
  Orientaciones Técnicas del año siguiente, se carga así el archivo nuevo, sin actualizar la aplicación.

## 8. Si algo no se ve

- **«Todavía no hay cifras REM»**: todavía no se carga ningún año. Revise «Cifras REM cargadas» en la administración.
- **Faltan sus propias cifras**: la instalación no tiene establecimiento configurado. Avise a quien la instaló.
- **Una meta sin porcentaje**: falta la población de FONASA de ese año, o el mes todavía no se publica.
- **Un mensaje de error rojo**: dice qué falló. Si pide recargar la página y el problema sigue, avise a quien
  administra Estadística.
