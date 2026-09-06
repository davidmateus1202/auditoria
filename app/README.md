# Auditoría SUH — aplicación móvil

Cliente Flutter de la auditoría al Sistema Único de Habilitación de los puestos
de salud de la E.S.E. Municipal de Villavicencio.

Flutter 3.27 · Riverpod · Dio · fl_chart.

## Ponerla a andar

```sh
flutter pub get
flutter run --dart-define=API_URL=http://10.0.2.2:8000/api   # emulador Android
flutter run -d chrome --dart-define=API_URL=http://127.0.0.1:8000/api
```

`API_URL` por omisión apunta a `10.0.2.2:8000`, que es como el emulador de
Android ve el `localhost` de la máquina.

La API vive en un servidor con conexión permanente, así que la app **siempre
trabaja en línea**: no hay base local ni sincronización que resolver.

## Cómo está organizado

```
lib/
  dominio/      modelos y enums, sin dependencias de Flutter salvo Color
  nucleo/       cliente de API, tema y formatos en es-CO
  datos/        un repositorio que devuelve modelos, nunca mapas sueltos
  widgets/      piezas compartidas: pastillas, barras, bloques, estados vacíos
  pantallas/    ingreso, inicio, hallazgos, detalle, corte, consolidado, serie
```

## Decisiones de interfaz

**El estado se lee antes que el número.** Cada hallazgo lleva una franja de
color a la izquierda y una pastilla a la derecha; el azul del acento nunca se
usa para señalar gravedad, porque competiría con el semáforo.

**Los tres cortes son la misma pantalla.** Por sede, por estándar y sede ×
estándar salen de un solo endpoint con distinta agrupación, igual que en el
backend: así el Excel exportado y lo que se ve no pueden divergir.

**El periodo siempre está a la vista.** Toda cifra dice de qué corte es, y un
corte abierto se marca en ámbar: sus números todavía pueden cambiar.

**Los errores dicen qué pasó.** El cliente desenvuelve la excepción de red para
que llegue el mensaje del servidor —«Cerrar un hallazgo exige registrar la
evidencia», o la hoja y fila exactas de un Excel mal formado— en vez del nombre
de la librería.

**Los estados vacíos explican qué falta.** Una lista sin resultados dice cuántos
filtros hay puestos y ofrece quitarlos; un mes sin cortes dice cómo abrir el
primero.
