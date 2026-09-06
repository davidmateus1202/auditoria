import 'enums.dart';

/// Utilidades de lectura tolerante: la API puede omitir campos opcionales y
/// una pantalla no debería caerse por eso.
int _entero(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;
double? _decimal(dynamic v) =>
    v == null ? null : (v is num ? v.toDouble() : double.tryParse('$v'));
String _texto(dynamic v) => v?.toString() ?? '';

class Sede {
  const Sede({
    required this.id,
    required this.codigo,
    required this.nombre,
    this.municipio = 'Villavicencio',
    this.responsable,
    this.activa = true,
    this.hallazgosVigentes = 0,
    this.hallazgosCerrados = 0,
    this.auditorias = 0,
  });

  final int id;
  final String codigo;
  final String nombre;
  final String municipio;
  final String? responsable;
  final bool activa;
  final int hallazgosVigentes;
  final int hallazgosCerrados;
  final int auditorias;

  int get totalHallazgos => hallazgosVigentes + hallazgosCerrados;

  double? get avance =>
      totalHallazgos == 0 ? null : hallazgosCerrados / totalHallazgos;

  factory Sede.desdeJson(Map<String, dynamic> j) => Sede(
        id: _entero(j['id']),
        codigo: _texto(j['codigo']),
        nombre: _texto(j['nombre']),
        municipio: _texto(j['municipio']),
        responsable: j['responsable'] as String?,
        activa: j['activa'] == true || j['activa'] == 1,
        hallazgosVigentes: _entero(j['hallazgos_vigentes']),
        hallazgosCerrados: _entero(j['hallazgos_cerrados']),
        auditorias: _entero(j['auditorias_count']),
      );
}

class Estandar {
  const Estandar({
    required this.codigo,
    required this.nombre,
    required this.orden,
    required this.esNormativo,
  });

  final String codigo;
  final String nombre;
  final int orden;
  final bool esNormativo;

  factory Estandar.desdeJson(Map<String, dynamic> j) => Estandar(
        codigo: _texto(j['codigo']),
        nombre: _texto(j['nombre']),
        orden: _entero(j['orden']),
        esNormativo: j['es_normativo'] == true || j['es_normativo'] == 1,
      );
}

class Hallazgo {
  const Hallazgo({
    required this.id,
    required this.descripcion,
    required this.estado,
    required this.codigoEstandar,
    this.nombreEstandar = '',
    this.sedeCodigo = '',
    this.sedeNombre = '',
    this.accionPropuesta,
    this.responsable,
    this.evidencia,
    this.vecesReportado = 1,
    this.servicio,
  });

  final int id;
  final String descripcion;
  final EstadoHallazgo estado;
  final String codigoEstandar;
  final String nombreEstandar;
  final String sedeCodigo;
  final String sedeNombre;
  final String? accionPropuesta;
  final String? responsable;
  final String? evidencia;
  final int vecesReportado;
  final String? servicio;

  factory Hallazgo.desdeJson(Map<String, dynamic> j) => Hallazgo(
        id: _entero(j['id']),
        descripcion: _texto(j['descripcion']),
        estado: EstadoHallazgo.desde(j['estado'] as String?),
        codigoEstandar: _texto(j['estandar_codigo']),
        nombreEstandar: _texto((j['estandar'] as Map?)?['nombre']),
        sedeCodigo: _texto((j['sede'] as Map?)?['codigo']),
        sedeNombre: _texto((j['sede'] as Map?)?['nombre']),
        accionPropuesta: j['accion_propuesta'] as String?,
        responsable: j['responsable'] as String?,
        evidencia: j['evidencia'] as String?,
        vecesReportado: _entero(j['veces_reportado']),
        servicio: (j['servicio'] as Map?)?['nombre'] as String?,
      );
}

class Corte {
  const Corte({
    required this.periodo,
    required this.estado,
    this.hallazgos = 0,
    this.reportados = 0,
    this.pendientes = 0,
    this.porSede = const {},
  });

  final String periodo;
  final String estado;
  final int hallazgos;
  final int reportados;
  final int pendientes;
  final Map<String, Map<String, int>> porSede;

  bool get abierto => estado == 'abierto';

  double get avanceDelMes =>
      hallazgos == 0 ? 0 : reportados / hallazgos;

  factory Corte.desdeJson(Map<String, dynamic> j) => Corte(
        periodo: _texto(j['periodo']),
        estado: _texto(j['estado']),
        hallazgos: _entero(j['hallazgos']),
        reportados: _entero(j['reportados']),
        pendientes: _entero(j['pendientes']),
        porSede: ((j['por_sede'] as Map?) ?? {}).map(
          (clave, valor) => MapEntry(
            '$clave',
            (valor as Map).map((k, v) => MapEntry('$k', _entero(v))),
          ),
        ),
      );
}

/// Bloque de cifras que se repite en todas las vistas del consolidado.
class Generales {
  const Generales({
    required this.hallazgos,
    required this.cerrados,
    required this.abiertosConEvidencia,
    required this.abiertos,
    required this.sinDato,
    this.sinReportar = 0,
    this.pctAvance,
    this.pctConGestion,
  });

  final int hallazgos;
  final int cerrados;
  final int abiertosConEvidencia;
  final int abiertos;
  final int sinDato;
  final int sinReportar;
  final double? pctAvance;
  final double? pctConGestion;

  factory Generales.desdeJson(Map<String, dynamic> j) => Generales(
        hallazgos: _entero(j['hallazgos']),
        cerrados: _entero(j['cerrados']),
        abiertosConEvidencia: _entero(j['abiertos_con_evidencia']),
        abiertos: _entero(j['abiertos']),
        sinDato: _entero(j['sin_dato']),
        sinReportar: _entero(j['sin_reportar']),
        pctAvance: _decimal(j['pct_avance']),
        pctConGestion: _decimal(j['pct_con_gestion']),
      );

  Map<EstadoHallazgo, int> get porEstado => {
        EstadoHallazgo.abierto: abiertos,
        EstadoHallazgo.abiertoConEvidencia: abiertosConEvidencia,
        EstadoHallazgo.cerrado: cerrados,
        EstadoHallazgo.sinDato: sinDato,
      };
}

/// Una fila de cualquiera de los tres cortes. Se usa el mismo tipo para las
/// tres agrupaciones porque es el mismo dato con distinta clave.
class FilaConsolidado {
  const FilaConsolidado({
    required this.clave,
    required this.nombre,
    required this.generales,
    this.pctDelTotal,
    this.estandares = const {},
    this.estandarDominante,
    this.pctDominante,
    this.mesAntiguoMeses = 0,
  });

  final String clave;
  final String nombre;
  final Generales generales;
  final double? pctDelTotal;
  final Map<String, int> estandares;
  final String? estandarDominante;
  final double? pctDominante;
  final int mesAntiguoMeses;

  factory FilaConsolidado.desdeJson(Map<String, dynamic> j) => FilaConsolidado(
        clave: _texto(j['sede'] ?? j['estandar']),
        nombre: _texto(j['nombre']),
        generales: Generales.desdeJson(j),
        pctDelTotal: _decimal(j['pct_del_total']),
        estandares: ((j['estandares'] as Map?) ?? {})
            .map((k, v) => MapEntry('$k', _entero(v))),
        estandarDominante: j['estandar_dominante'] as String?,
        pctDominante: _decimal(j['pct_dominante']),
        mesAntiguoMeses: _entero(j['mas_antiguo_meses']),
      );

  /// En la matriz sede × estándar el total viene aparte del bloque general.
  int get total =>
      estandares.isEmpty ? generales.hallazgos : estandares.values.fold(0, (a, b) => a + b);
}

class Consolidado {
  const Consolidado({
    required this.periodo,
    required this.corteCerrado,
    required this.generales,
    required this.porSede,
    required this.porEstandar,
    required this.sedePorEstandar,
  });

  final String periodo;
  final bool corteCerrado;
  final Generales generales;
  final List<FilaConsolidado> porSede;
  final List<FilaConsolidado> porEstandar;
  final List<FilaConsolidado> sedePorEstandar;

  List<FilaConsolidado> agrupadoPor(AgrupacionConsolidado agrupacion) =>
      switch (agrupacion) {
        AgrupacionConsolidado.sede => porSede,
        AgrupacionConsolidado.estandar => porEstandar,
        AgrupacionConsolidado.sedeEstandar => sedePorEstandar,
      };

  static List<FilaConsolidado> _filas(dynamic lista) =>
      ((lista as List?) ?? [])
          .map((e) => FilaConsolidado.desdeJson(e as Map<String, dynamic>))
          .toList();

  factory Consolidado.desdeJson(Map<String, dynamic> j) => Consolidado(
        periodo: _texto(j['periodo']),
        corteCerrado: j['corte_cerrado'] == true,
        generales: Generales.desdeJson(
            (j['generales'] as Map?)?.cast<String, dynamic>() ?? {}),
        porSede: _filas(j['por_sede']),
        porEstandar: _filas(j['por_estandar']),
        sedePorEstandar: _filas(j['sede_por_estandar']),
      );
}

class PuntoSerie {
  const PuntoSerie({
    required this.periodo,
    required this.generales,
    required this.cerrado,
  });

  final String periodo;
  final Generales generales;
  final bool cerrado;

  factory PuntoSerie.desdeJson(Map<String, dynamic> j) => PuntoSerie(
        periodo: _texto(j['periodo']),
        generales: Generales.desdeJson(j),
        cerrado: j['cerrado'] == true,
      );
}

class Usuario {
  const Usuario({required this.id, required this.nombre, required this.email});

  final int id;
  final String nombre;
  final String email;

  factory Usuario.desdeJson(Map<String, dynamic> j) => Usuario(
        id: _entero(j['id']),
        nombre: _texto(j['nombre']),
        email: _texto(j['email']),
      );
}
